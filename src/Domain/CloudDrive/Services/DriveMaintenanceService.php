<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlobs;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkResult;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeCounts;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Repo\DB\Blobs\DBDriveBlob;
use DDD\Domain\CloudDrive\Repo\DB\Changes\DBDriveChangeEvent;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopy;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersions;
use DDD\Domain\CloudDrive\Repo\DB\Blobs\DBDriveBlobs;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNode;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNodes;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersion;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersions;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopyEntry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Housekeeping without rights (a cron or an operator runs it): the trash past its retention, versions beyond the
 * kept ones and their retention, expired system-folder files (`work/`), and the blob sweep — unfinished uploads,
 * unreferenced staged blobs and blobs marked for purge. Every method answers lines of what it did (or would do with
 * `$isDryRun`).
 */
class DriveMaintenanceService extends Service
{
    public const int BATCH_SIZE = 500;

    /** @var int Trashed nodes one empty-trash call purges at most */
    public const int MAX_NODES_PER_EMPTY_TRASH = 200;

    /**
     * @return string[]
     */
    public function runMaintenance(bool $isDryRun = false): array
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return [
                ...$this->expireSystemFolderFiles($isDryRun),
                ...$this->purgeExpiredTrash($isDryRun),
                ...$this->purgeVersionsBeyondRetention($isDryRun),
                ...$this->repairScanFailuresStoredAsBlocked($isDryRun),
                ...$this->rescanFailedScans($isDryRun),
                ...$this->reingestStaleVersions($isDryRun),
                ...$this->sweepBlobs($isDryRun),
            ];
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Trashes files of system folders (`work/`) unchanged for longer than the folder's expiry.
     *
     * @return string[]
     */
    public function expireSystemFolderFiles(bool $isDryRun): array
    {
        $systemFolders = Config::get('CloudDrive.settings.systemFolders');
        $expiryDays = 7;
        foreach (is_array($systemFolders) ? $systemFolders : [] as $systemFolderSettings) {
            $expiryDays = (int)($systemFolderSettings['expiryDays'] ?? $expiryDays);
        }
        $repoClass = new DBDriveNodes();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.isSystem = :isSystem")
            ->andWhere("{$alias}.nodeType = :nodeType")
            ->andWhere("{$alias}.trashedAt IS NULL")
            ->andWhere("COALESCE({$alias}.updated, {$alias}.created) < :expiredBefore")
            ->setParameter('isSystem', true)
            ->setParameter('nodeType', DriveNode::NODE_TYPE_FILE)
            ->setParameter('expiredBefore', DateTime::fromTimestamp(time() - $expiryDays * 86400)->format('Y-m-d H:i:s'))
            ->setMaxResults(self::BATCH_SIZE);
        $expiredNodes = $repoClass->find($queryBuilder, false);
        $resultLines = [];
        foreach (($expiredNodes instanceof DriveNodes ? $expiredNodes->getElements() : []) as $expiredNode) {
            $resultLines[] = sprintf('%s system file %d of space %d (unchanged for %d days)', $isDryRun ? 'would trash' : 'trashed', (int)$expiredNode->id, (int)$expiredNode->driveSpaceId, $expiryDays);
            if (!$isDryRun) {
                $this->getDriveNodesService()->trashNode($expiredNode);
            }
        }
        return $resultLines;
    }

    /**
     * Deletes trashed nodes past their space's trash retention, with their versions.
     *
     * @return string[]
     */
    public function purgeExpiredTrash(bool $isDryRun): array
    {
        $resultLines = [];
        foreach ($this->findSpaces()->getElements() as $driveSpace) {
            $repoClass = new DBDriveNodes();
            $queryBuilder = $repoClass::createQueryBuilder(true);
            $alias = $repoClass::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
                ->andWhere("{$alias}.trashedAt < :trashedBefore")
                ->setParameter('driveSpaceId', (int)$driveSpace->id)
                ->setParameter('trashedBefore', DateTime::fromTimestamp(time() - $driveSpace->getTrashRetentionDays() * 86400)->format('Y-m-d H:i:s'))
                ->setMaxResults(self::BATCH_SIZE);
            $trashedNodes = $repoClass->find($queryBuilder, false);
            foreach (($trashedNodes instanceof DriveNodes ? $trashedNodes->getElements() : []) as $trashedNode) {
                $resultLines[] = sprintf('%s trashed node %d of space %d', $isDryRun ? 'would purge' : 'purged', (int)$trashedNode->id, (int)$driveSpace->id);
                if (!$isDryRun) {
                    $purgedCounts = new DriveNodeCounts();
                    $this->purgeNode($trashedNode, $purgedCounts);
                    $this->getDriveNodeLifecycleListenersService()->notifyNodesPurged($driveSpace, $purgedCounts->driveNodeIds);
                }
            }
        }
        return $resultLines;
    }

    /**
     * Deletes versions that are neither among the newest kept ones nor younger than the retention, never the current
     * or the newest version of a file.
     *
     * @return string[]
     */
    public function purgeVersionsBeyondRetention(bool $isDryRun): array
    {
        $resultLines = [];
        foreach ($this->findSpaces()->getElements() as $driveSpace) {
            $repoClass = new DBDriveFileVersions();
            $queryBuilder = $repoClass::createQueryBuilder(true);
            $alias = $repoClass::getBaseModelAlias();
            $nodeModel = DBDriveNode::BASE_ORM_MODEL;
            $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
                ->andWhere("{$alias}.created < :createdBefore")
                ->andWhere("{$alias}.versionNumber + :keptVersions <= (SELECT MAX(retentionNode.versionNumber) FROM {$nodeModel} retentionNode WHERE retentionNode.id = {$alias}.driveNodeId)")
                ->andWhere("{$alias}.id NOT IN (SELECT COALESCE(currentNode.currentVersionId, 0) FROM {$nodeModel} currentNode WHERE currentNode.id = {$alias}.driveNodeId)")
                ->setParameter('driveSpaceId', (int)$driveSpace->id)
                ->setParameter('createdBefore', DateTime::fromTimestamp(time() - $driveSpace->getVersionRetentionDays() * 86400)->format('Y-m-d H:i:s'))
                ->setParameter('keptVersions', max(1, $driveSpace->getKeptVersionCount()))
                ->setMaxResults(self::BATCH_SIZE);
            $expiredVersions = $repoClass->find($queryBuilder, false);
            foreach (($expiredVersions instanceof DriveFileVersions ? $expiredVersions->getElements() : []) as $expiredVersion) {
                $resultLines[] = sprintf('%s version %d of node %d', $isDryRun ? 'would purge' : 'purged', (int)$expiredVersion->id, (int)$expiredVersion->driveNodeId);
                if (!$isDryRun) {
                    $this->purgeVersion($expiredVersion);
                }
            }
        }
        return $resultLines;
    }

    /**
     * Puts blobs that were stored BLOCKED although their scan only failed (rc.1) back on the retry path, so the next
     * step ({@see rescanFailedScans()}) scans them again and releases their versions when clean. Idempotent: once
     * repaired, nothing matches any more. Answers one line per blob and a count.
     *
     * @return string[]
     */
    public function repairScanFailuresStoredAsBlocked(bool $isDryRun): array
    {
        $resultLines = [];
        $repairedCount = 0;
        foreach ($this->getDriveBlobsService()->findBlobsBlockedByFailedScan(self::BATCH_SIZE)->getElements() as $blockedBlob) {
            if ($isDryRun) {
                $resultLines[] = sprintf('would put blob %d of space %d back to the scan (stored BLOCKED, but its scan failed)', (int)$blockedBlob->id, (int)$blockedBlob->driveSpaceId);
                $repairedCount++;
                continue;
            }
            if ($this->getDriveBlobsService()->markBlockedScanFailureForRescan($blockedBlob)) {
                $resultLines[] = sprintf('put blob %d of space %d back to the scan (stored BLOCKED, but its scan failed)', (int)$blockedBlob->id, (int)$blockedBlob->driveSpaceId);
                $repairedCount++;
            }
        }
        if ($repairedCount > 0) {
            $resultLines[] = sprintf('%s %d blob(s) stored BLOCKED after a failed scan', $isDryRun ? 'would repair' : 'repaired', $repairedCount);
        }
        return $resultLines;
    }

    /**
     * Scans blobs again whose scan failed (scanner missing, no signatures) and releases their versions when clean.
     *
     * @return string[]
     */
    public function rescanFailedScans(bool $isDryRun): array
    {
        $resultLines = [];
        foreach ($this->getDriveBlobsService()->findBlobsWithFailedScan(self::BATCH_SIZE)->getElements() as $failedBlob) {
            if ($isDryRun) {
                $resultLines[] = sprintf('would scan blob %d of space %d again', (int)$failedBlob->id, (int)$failedBlob->driveSpaceId);
                continue;
            }
            try {
                $resultLines[] = sprintf('scanned blob %d again: %s', (int)$failedBlob->id, $this->getDriveVersionsService()->rescanBlob($failedBlob));
            } catch (Throwable $throwable) {
                $resultLines[] = sprintf('blob %d could not be scanned again: %s', (int)$failedBlob->id, $throwable->getMessage());
            }
        }
        return $resultLines;
    }

    /**
     * Redoes the ingest of versions whose blob still waits (PENDING or STAGED) longer than `staleIngestMinutes`: a worker
     * run that was lost (retries exhausted, a crash). Runs the ingest here, not in a worker. The age is the blob's last
     * change, or its creation when no change was stamped (partial writes leave `updated` empty); a second ingest is
     * harmless.
     *
     * @return string[]
     */
    public function reingestStaleVersions(bool $isDryRun): array
    {
        $staleMinutes = max(1, (int)(Config::get('CloudDrive.settings.staleIngestMinutes') ?? 15));
        $repoClass = new DBDriveFileVersions();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $blobModel = DBDriveBlob::BASE_ORM_MODEL;
        $queryBuilder->andWhere("{$alias}.driveBlobId IN (SELECT staleBlob.id FROM {$blobModel} staleBlob WHERE staleBlob.state IN (:waitingStates) AND COALESCE(staleBlob.updated, staleBlob.created) < :staleBefore)")
            ->setParameter('waitingStates', [DriveBlob::STATE_PENDING, DriveBlob::STATE_STAGED])
            ->setParameter('staleBefore', DateTime::fromTimestamp(time() - $staleMinutes * 60)->format('Y-m-d H:i:s'))
            ->orderBy("{$alias}.id", 'ASC')
            ->setMaxResults(self::BATCH_SIZE);
        $staleVersions = $repoClass->find($queryBuilder, false);
        $resultLines = [];
        foreach (($staleVersions instanceof DriveFileVersions ? $staleVersions->getElements() : []) as $staleVersion) {
            if ($isDryRun) {
                $resultLines[] = sprintf('would ingest version %d of node %d again (waiting over %d minutes)', (int)$staleVersion->id, (int)$staleVersion->driveNodeId, $staleMinutes);
                continue;
            }
            try {
                $staleNode = $this->getDriveNodesService()->find((int)$staleVersion->driveNodeId, false);
                $this->getDriveVersionsService()->ingestVersion($staleVersion, !($staleNode instanceof DriveNode && $staleNode->isSystem), null, false);
                $resultLines[] = sprintf('ingested version %d of node %d again', (int)$staleVersion->id, (int)$staleVersion->driveNodeId);
            } catch (Throwable $throwable) {
                $resultLines[] = sprintf('version %d could not be ingested again: %s', (int)$staleVersion->id, $throwable->getMessage());
            }
        }
        return $resultLines;
    }

    /**
     * Removes blobs nothing needs: unfinished uploads and staged blobs past their expiry that no entry and no version
     * references, and blobs marked for purge; of one space when `$driveSpaceId` is given.
     *
     * @return string[]
     */
    public function sweepBlobs(bool $isDryRun, ?int $driveSpaceId = null): array
    {
        $repoClass = new DBDriveBlobs();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        if ($driveSpaceId !== null) {
            $queryBuilder->andWhere("{$alias}.driveSpaceId = :sweptDriveSpaceId")->setParameter('sweptDriveSpaceId', $driveSpaceId);
        }
        $versionModel = DBDriveFileVersion::BASE_ORM_MODEL;
        $entryModel = DBDriveWorkingCopyEntry::BASE_ORM_MODEL;
        $queryBuilder->andWhere("({$alias}.state = :purgingState OR ({$alias}.state IN (:expiringStates) AND {$alias}.expiresAt < :now))")
            ->andWhere("{$alias}.id NOT IN (SELECT COALESCE(sweepVersion.driveBlobId, 0) FROM {$versionModel} sweepVersion WHERE sweepVersion.driveBlobId = {$alias}.id)")
            ->andWhere("{$alias}.id NOT IN (SELECT COALESCE(sweepEntry.stagedDriveBlobId, 0) FROM {$entryModel} sweepEntry WHERE sweepEntry.stagedDriveBlobId = {$alias}.id)")
            ->setParameter('purgingState', DriveBlob::STATE_PURGING)
            ->setParameter('expiringStates', [DriveBlob::STATE_UPLOADING, DriveBlob::STATE_STAGED, DriveBlob::STATE_FAILED])
            ->setParameter('now', DateTime::fromTimestamp(time())->format('Y-m-d H:i:s'))
            ->setMaxResults(self::BATCH_SIZE);
        $sweptBlobs = $repoClass->find($queryBuilder, false);
        $resultLines = [];
        foreach (($sweptBlobs instanceof DriveBlobs ? $sweptBlobs->getElements() : []) as $sweptBlob) {
            $resultLines[] = sprintf('%s blob %d (%s, %d bytes) of space %d', $isDryRun ? 'would sweep' : 'swept', (int)$sweptBlob->id, (string)$sweptBlob->state, (int)$sweptBlob->sizeBytes, (int)$sweptBlob->driveSpaceId);
            if (!$isDryRun) {
                try {
                    $this->getDriveBlobsService()->purgeBlob($sweptBlob);
                } catch (Throwable $throwable) {
                    $resultLines[] = sprintf('blob %d could not be swept: %s', (int)$sweptBlob->id, $throwable->getMessage());
                }
            }
        }
        return $resultLines;
    }

    /**
     * Erases a space with everything in it: nodes, versions, blobs (objects deleted from the store), the feed, the
     * working copies and the space row. Without rights (an operator or a harness decided it).
     *
     * @return string[]
     */
    public function eraseSpace(DriveSpace $driveSpace): array
    {
        $driveSpaceId = (int)$driveSpace->id;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $erasedNodeIds = array_values(array_map('intval', DBDriveNode::createQueryBuilder()
                ->select('erasedNode.id')->from(DBDriveNode::BASE_ORM_MODEL, 'erasedNode')
                ->where('erasedNode.driveSpaceId = :driveSpaceId')->setParameter('driveSpaceId', $driveSpaceId)
                ->getQuery()->getSingleColumnResult()));
            $blobRepoClass = new DBDriveBlobs();
            $queryBuilder = $blobRepoClass::createQueryBuilder(true);
            $alias = $blobRepoClass::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")->setParameter('driveSpaceId', $driveSpaceId);
            $driveBlobs = $blobRepoClass->find($queryBuilder, false);
            $objectStorage = $this->getDriveBlobsService()->getObjectStorage();
            $storageKeys = [];
            foreach (($driveBlobs instanceof DriveBlobs ? $driveBlobs->getElements() : []) as $driveBlob) {
                if ($driveBlob->multipartUploadId !== null) {
                    $objectStorage->abortMultipartUpload((string)$driveBlob->storageKey, $driveBlob->multipartUploadId);
                }
                $storageKeys[] = (string)$driveBlob->storageKey;
            }
            $objectStorage->deleteObjects($storageKeys);
            foreach ([
                DBDriveWorkingCopyEntry::BASE_ORM_MODEL,
                DBDriveWorkingCopy::BASE_ORM_MODEL,
                DBDriveChangeEvent::BASE_ORM_MODEL,
                DBDriveFileVersion::BASE_ORM_MODEL,
                DBDriveBlob::BASE_ORM_MODEL,
            ] as $modelClass) {
                DBDriveNode::createQueryBuilder()->delete($modelClass, 'row')->where('row.driveSpaceId = :driveSpaceId')
                    ->setParameter('driveSpaceId', $driveSpaceId)->getQuery()->execute();
            }
            // children before parents: a folder's foreign key holds no node, so one statement removes them all
            DBDriveNode::createQueryBuilder()->delete(DBDriveNode::BASE_ORM_MODEL, 'node')->where('node.driveSpaceId = :driveSpaceId')
                ->setParameter('driveSpaceId', $driveSpaceId)->getQuery()->execute();
            /** @var DriveSpacesService $driveSpacesService */
            $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
            DBDriveNode::createQueryBuilder()->delete($driveSpacesService->getSpaceModelClass(), 'space')->where('space.id = :driveSpaceId')
                ->setParameter('driveSpaceId', $driveSpaceId)->getQuery()->execute();
            /** @var DriveActivityEventsService $driveActivityEventsService */
            $driveActivityEventsService = DDDService::instance()->getService(DriveActivityEventsService::class);
            $driveActivityEventsService->deleteActivityOfSpace($driveSpaceId);
            $this->getDriveNodeLifecycleListenersService()->notifyNodesPurged($driveSpace, $erasedNodeIds);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return [sprintf('erased drive space %d (%d objects)', $driveSpaceId, count($storageKeys ?? []))];
    }

    /**
     * Deletes trashed nodes of a space for good, now: each with its subtree and versions, a PURGED change each; then
     * the space's blobs nothing references any more are deleted from the store and their bytes given back to the
     * quota. Needs the manage right on the space. A node that is not in the trash, or was trashed with its folder, is
     * skipped with its reason.
     *
     * @param int[] $driveNodeIds
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function purgeTrashedNodes(DriveSpace $driveSpace, array $driveNodeIds): DriveNodeBulkResult
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_MANAGE);
        $driveNodeIds = DriveNodesService::normalizeBulkNodeIds($driveNodeIds);
        $driveNodeBulkResult = new DriveNodeBulkResult();
        $trashedNodes = new DriveNodes();
        foreach ($driveNodeIds as $driveNodeId) {
            $driveNode = $this->getDriveNodesService()->findNodeOfSpace($driveSpace, $driveNodeId);
            $skipReason = match (true) {
                $driveNode === null => 'not found in this space',
                !$driveNode->isTrashed() => 'not in the trash',
                $driveNode->trashedWithNodeId !== null => 'trashed with its folder: purge the folder',
                default => null,
            };
            if ($skipReason !== null) {
                $driveNodeBulkResult->addSkip($driveNodeId, $skipReason);
                continue;
            }
            $trashedNodes->add($driveNode);
        }
        $this->purgeNodesOfSpace($driveSpace, $trashedNodes, $driveNodeBulkResult);
        return $driveNodeBulkResult;
    }

    /**
     * Empties a space's trash for good (see {@see purgeTrashedNodes()}); at most {@see MAX_NODES_PER_EMPTY_TRASH}
     * trashed nodes per call — `hasMore` asks for another call.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function emptyTrash(DriveSpace $driveSpace): DriveNodeBulkResult
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_MANAGE);
        $driveNodeBulkResult = new DriveNodeBulkResult();
        $trashedNodes = $this->getDriveNodesService()->findTrashedNodes($driveSpace, 0, self::MAX_NODES_PER_EMPTY_TRASH + 1);
        $trashedNodesToPurge = new DriveNodes();
        foreach ($trashedNodes->getElements() as $trashedNode) {
            if ($trashedNodesToPurge->count() >= self::MAX_NODES_PER_EMPTY_TRASH) {
                $driveNodeBulkResult->hasMore = true;
                break;
            }
            $trashedNodesToPurge->add($trashedNode);
        }
        $this->purgeNodesOfSpace($driveSpace, $trashedNodesToPurge, $driveNodeBulkResult);
        return $driveNodeBulkResult;
    }

    /**
     * Purges the given trashed nodes in one transaction, then sweeps the space's unreferenced blobs (store deletes
     * are no part of a transaction; a sweep that fails is redone by the maintenance).
     */
    protected function purgeNodesOfSpace(DriveSpace $driveSpace, DriveNodes $trashedNodes, DriveNodeBulkResult $driveNodeBulkResult): void
    {
        if ($trashedNodes->count() === 0) {
            return;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $connection->beginTransaction();
        $purgedNodeIds = [];
        try {
            foreach ($trashedNodes->getElements() as $trashedNode) {
                $purgedCounts = new DriveNodeCounts();
                $this->purgeNode($trashedNode, $purgedCounts);
                $driveNodeBulkResult->processedCount++;
                $driveNodeBulkResult->fileCount += $purgedCounts->fileCount;
                $driveNodeBulkResult->folderCount += $purgedCounts->folderCount;
                array_push($purgedNodeIds, ...$purgedCounts->driveNodeIds);
            }
            $connection->commit();
        } catch (Throwable $throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
        $this->getDriveNodeLifecycleListenersService()->notifyNodesPurged($driveSpace, $purgedNodeIds);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $this->sweepBlobs(false, (int)$driveSpace->id);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** Deletes a node (a folder with everything below it) and its versions; their blobs are swept when unreferenced */
    public function purgeNode(DriveNode $driveNode, ?DriveNodeCounts $purgedCounts = null): void
    {
        if ($purgedCounts !== null) {
            $driveNode->isFolder() ? $purgedCounts->folderCount++ : $purgedCounts->fileCount++;
            $purgedCounts->driveNodeIds[] = (int)$driveNode->id;
        }
        if ($driveNode->isFolder()) {
            $repoClass = new DBDriveNodes();
            $queryBuilder = $repoClass::createQueryBuilder(true);
            $alias = $repoClass::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.parentNodeId = :parentNodeId")->setParameter('parentNodeId', (int)$driveNode->id);
            $childNodes = $repoClass->find($queryBuilder, false);
            foreach (($childNodes instanceof DriveNodes ? $childNodes->getElements() : []) as $childNode) {
                $this->purgeNode($childNode, $purgedCounts);
            }
        }
        foreach ($this->getDriveVersionsService()->findVersionsOfNode($driveNode, 500)->getElements() as $driveFileVersion) {
            $this->purgeVersion($driveFileVersion);
        }
        $this->getDriveChangesService()->recordChange($driveNode, DriveChangeEvent::EVENT_PURGED);
        DBDriveNode::createQueryBuilder()
            ->delete(DBDriveNode::BASE_ORM_MODEL, 'node')
            ->where('node.id = :nodeId')
            ->setParameter('nodeId', (int)$driveNode->id)
            ->getQuery()->execute();
    }

    /** Deletes a version row; its blob is marked for purge when no other version shares it */
    public function purgeVersion(DriveFileVersion $driveFileVersion): void
    {
        $driveBlobId = (int)$driveFileVersion->driveBlobId;
        DBDriveFileVersion::createQueryBuilder()
            ->delete(DBDriveFileVersion::BASE_ORM_MODEL, 'version')
            ->where('version.id = :versionId')
            ->setParameter('versionId', (int)$driveFileVersion->id)
            ->getQuery()->execute();
        $remainingReferenceCount = (int)DBDriveFileVersion::createQueryBuilder()
            ->select('COUNT(version.id)')
            ->from(DBDriveFileVersion::BASE_ORM_MODEL, 'version')
            ->where('version.driveBlobId = :driveBlobId')
            ->setParameter('driveBlobId', $driveBlobId)
            ->getQuery()->getSingleScalarResult();
        if ($remainingReferenceCount === 0) {
            $driveBlob = $this->getDriveBlobsService()->find($driveBlobId, false);
            if ($driveBlob instanceof DriveBlob) {
                $this->getDriveBlobsService()->markForPurge($driveBlob);
            }
        }
    }

    protected function findSpaces(): DriveSpaces
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        $driveSpaces = $driveSpacesService->findAll(null, 10000, false);
        return $driveSpaces instanceof DriveSpaces ? $driveSpaces : new DriveSpaces();
    }

    protected function getDriveNodeLifecycleListenersService(): DriveNodeLifecycleListenersService
    {
        /** @var DriveNodeLifecycleListenersService $driveNodeLifecycleListenersService */
        $driveNodeLifecycleListenersService = DDDService::instance()->getService(DriveNodeLifecycleListenersService::class);
        return $driveNodeLifecycleListenersService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        return $driveNodesService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        return $driveVersionsService;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService;
    }
}
