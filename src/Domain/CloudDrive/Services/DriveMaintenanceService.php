<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlobs;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
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
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersion;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersions;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopyEntry;
use DDD\Infrastructure\Base\DateTime\DateTime;
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
                ...$this->rescanFailedScans($isDryRun),
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
            ->andWhere("{$alias}.updated < :expiredBefore")
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
                    $this->purgeNode($trashedNode);
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
     * Removes blobs nothing needs: unfinished uploads and staged blobs past their expiry that no entry and no version
     * references, and blobs marked for purge.
     *
     * @return string[]
     */
    public function sweepBlobs(bool $isDryRun): array
    {
        $repoClass = new DBDriveBlobs();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
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
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return [sprintf('erased drive space %d (%d objects)', $driveSpaceId, count($storageKeys ?? []))];
    }

    /** Deletes a node (a folder with everything below it) and its versions; their blobs are swept when unreferenced */
    public function purgeNode(DriveNode $driveNode): void
    {
        if ($driveNode->isFolder()) {
            $repoClass = new DBDriveNodes();
            $queryBuilder = $repoClass::createQueryBuilder(true);
            $alias = $repoClass::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.parentNodeId = :parentNodeId")->setParameter('parentNodeId', (int)$driveNode->id);
            $childNodes = $repoClass->find($queryBuilder, false);
            foreach (($childNodes instanceof DriveNodes ? $childNodes->getElements() : []) as $childNode) {
                $this->purgeNode($childNode);
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
