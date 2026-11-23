<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersionListItem;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersionListItems;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersions;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWrite;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWriteResult;
use DDD\Domain\CloudDrive\MessageHandlers\DriveIngestVersionMessage;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNode;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersion;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Throwable;

/**
 * Writing files: a new file or a new version of one, guarded by a compare and swap on the node's write head
 * (`latestVersionId`), never a silent overwrite. Releasing a version after its blob's ingest moves the read head
 * (`currentVersionId`). Equal content to the newest version writes no version.
 *
 * @method DriveFileVersion|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DBDriveFileVersion getEntityRepoClassInstance()
 * @method DBDriveFileVersions getEntitySetRepoClassInstance()
 */
class DriveVersionsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DriveFileVersion::class;

    /** @var string The comment of a restored version (`%d` = the restored version's number) */
    public const string RESTORED_VERSION_COMMENT = 'Restored from v%d';

    /**
     * Writes a file at `$name` in a folder: a new node when none is live there (and none is expected), else a new
     * version on top of `expectedLatestVersionId`. A moved write head or a taken name is a conflict, not an error.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function writeFile(DriveSpace $driveSpace, DriveNode $folderNode, string $name, DriveFileWrite $driveFileWrite): DriveFileWriteResult
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
        $invalidNameReason = DriveNode::findInvalidNameReason($name);
        if ($invalidNameReason !== null) {
            throw new BadRequestException(sprintf('"%s": %s.', $name, $invalidNameReason));
        }
        $fileNameRefusalReason = $this->getDriveTypePoliciesService()
            ->buildTypePolicyForSpace($driveSpace, $this->getDriveSpacesService()->getCurrentAudience())
            ->findFileNameRefusalReason($name);
        if ($fileNameRefusalReason !== null) {
            throw new BadRequestException(sprintf('"%s" is not taken: %s.', $name, $fileNameRefusalReason));
        }
        $driveBlob = $this->getDriveBlobsService()->find($driveFileWrite->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob || $driveBlob->driveSpaceId !== $driveSpace->id) {
            throw new BadRequestException(sprintf('Blob %d is no blob of this space.', $driveFileWrite->driveBlobId));
        }
        $existingNode = $this->getDriveNodesService()->findChildByName($folderNode, $name);
        if ($existingNode !== null && !$existingNode->isFile()) {
            return $this->buildConflict($existingNode, null, sprintf('"%s" is a folder', $name));
        }
        if ($existingNode === null) {
            if (!$driveFileWrite->ignoresExpectedVersion && $driveFileWrite->expectedLatestVersionId !== null) {
                return $this->buildConflict(null, null, sprintf('"%s" was trashed or moved meanwhile', $name));
            }
            return $this->writeNewFile($driveSpace, $folderNode, $name, $driveBlob, $driveFileWrite);
        }
        return $this->writeVersionOfNode($existingNode, $driveBlob, $driveFileWrite);
    }

    /**
     * A new version of an existing file node (compare and swap on its write head).
     *
     * @throws InternalErrorException
     */
    public function writeVersionOfNode(DriveNode $driveNode, DriveBlob $driveBlob, DriveFileWrite $driveFileWrite): DriveFileWriteResult
    {
        $baseVersionId = $driveFileWrite->ignoresExpectedVersion ? $driveNode->latestVersionId : $driveFileWrite->expectedLatestVersionId;
        if (!$driveFileWrite->ignoresExpectedVersion && $baseVersionId !== $driveNode->latestVersionId) {
            return $this->buildConflict($driveNode, $driveNode->latestVersionId, 'the file has a newer version');
        }
        if ($driveBlob->sha256 !== null && $driveBlob->sha256 === $driveNode->sha256 && (int)$driveBlob->id !== 0) {
            $driveFileWriteResult = new DriveFileWriteResult();
            $driveFileWriteResult->driveNode = $driveNode;
            $driveFileWriteResult->isUnchanged = true;
            return $driveFileWriteResult;
        }
        $driveFileVersion = $this->buildVersion($driveNode, $driveBlob, $driveFileWrite, (int)$driveNode->versionNumber + 1);
        try {
            $driveFileVersion = $this->storeVersion($driveFileVersion);
        } catch (UniqueConstraintViolationException) {
            return $this->buildConflict($driveNode, null, 'another version was written at the same moment');
        }
        if (!$this->swapWriteHead($driveNode, $baseVersionId, $driveFileVersion, $driveBlob)) {
            $this->deleteVersionRow($driveFileVersion);
            $freshNode = $this->getDriveNodesService()->find((int)$driveNode->id, false);
            return $this->buildConflict($driveNode, $freshNode instanceof DriveNode ? $freshNode->latestVersionId : null, 'the file has a newer version');
        }
        $this->getDriveChangesService()->recordChange($driveNode, DriveChangeEvent::EVENT_VERSIONED, (int)$driveFileVersion->id, $this->getDriveNodesService()->buildPath($driveNode), workingCopyKey: $driveFileWrite->workingCopyKey, onBehalfOfAccountId: $driveFileWrite->onBehalfOfAccountId);
        $driveFileWriteResult = new DriveFileWriteResult();
        $driveFileWriteResult->driveNode = $driveNode;
        $driveFileWriteResult->driveFileVersion = $driveFileVersion;
        return $driveFileWriteResult;
    }

    /**
     * The version's blob was released by the ingest: the node's read head moves to it when it is the newest released
     * one. A blocked blob leaves the read head where it was.
     */
    public function releaseVersion(DriveFileVersion $driveFileVersion, DriveBlob $releasedBlob, ?string $workingCopyKey = null): void
    {
        if ($releasedBlob->state !== DriveBlob::STATE_RELEASED) {
            return;
        }
        if ((int)$driveFileVersion->driveBlobId !== (int)$releasedBlob->id) {
            $driveFileVersion->driveBlobId = (int)$releasedBlob->id;
            $driveFileVersion->updatePartialIgnoringRights('driveBlobId');
        }
        $driveFileVersion->sha256 = $releasedBlob->sha256;
        $driveFileVersion->updatePartialIgnoringRights('sha256');
        DBDriveNode::createQueryBuilder()
            ->update(DBDriveNode::BASE_ORM_MODEL, 'node')
            ->set('node.currentVersionId', ':versionId')
            ->set('node.sha256', 'CASE WHEN node.latestVersionId = :versionId THEN :sha256 ELSE node.sha256 END')
            ->where('node.id = :nodeId')
            ->andWhere('(node.currentVersionId IS NULL OR node.currentVersionId < :versionId)')
            ->setParameter('versionId', (int)$driveFileVersion->id)
            ->setParameter('sha256', (string)$releasedBlob->sha256)
            ->setParameter('nodeId', (int)$driveFileVersion->driveNodeId)
            ->getQuery()->execute();
        $driveNode = $this->getDriveNodesService()->find((int)$driveFileVersion->driveNodeId, false);
        if ($driveNode instanceof DriveNode) {
            $this->getDriveChangesService()->recordChange($driveNode, DriveChangeEvent::EVENT_RELEASED, (int)$driveFileVersion->id, $this->getDriveNodesService()->buildPath($driveNode), workingCopyKey: $workingCopyKey);
        }
    }

    /**
     * The ingest of a new version: in a worker when `CloudDrive.settings.asyncIngest` is on (or `$async` says so) —
     * {@see DriveIngestVersionMessage} on the transport `drive_ingest` — else right here. Every write path (upload,
     * working copy commit, copy) goes through it; a lost worker run is redone by the maintenance.
     *
     * @throws InternalErrorException
     */
    public function ingestVersion(DriveFileVersion $driveFileVersion, bool $isScanned = true, ?string $workingCopyKey = null, ?bool $async = null): void
    {
        if ($async ?? (bool)Config::get('CloudDrive.settings.asyncIngest')) {
            (new DriveIngestVersionMessage((int)$driveFileVersion->id, $isScanned, $workingCopyKey))->dispatch();
            return;
        }
        $this->ingestAndReleaseVersion($driveFileVersion, $isScanned, $workingCopyKey);
    }

    /**
     * Runs the ingest of a new version's blob and releases the version (synchronously; a host may move it to a
     * worker). A file in a system folder is not scanned.
     *
     * @throws InternalErrorException
     */
    public function ingestAndReleaseVersion(DriveFileVersion $driveFileVersion, bool $isScanned = true, ?string $workingCopyKey = null): DriveBlob
    {
        $driveBlob = $this->getDriveBlobsService()->find((int)$driveFileVersion->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob) {
            throw new InternalErrorException(sprintf('Version %d has no blob.', (int)$driveFileVersion->id));
        }
        $driveNode = $this->getDriveNodesService()->find((int)$driveFileVersion->driveNodeId, false);
        $driveSpace = $driveNode instanceof DriveNode ? $this->getDriveSpacesService()->find((int)$driveNode->driveSpaceId, false) : null;
        // the staff's policy of the kind: an audience's own narrower rules were checked when the file was written
        $driveTypePolicy = $driveSpace instanceof DriveSpace
            ? $this->getDriveTypePoliciesService()->buildTypePolicyForSpace($driveSpace, DriveSpace::AUDIENCE_INTERNAL)
            : null;
        $effectiveBlob = $this->getDriveBlobsService()->ingestBlob($driveBlob, $isScanned, $driveTypePolicy, $driveNode instanceof DriveNode ? (string)$driveNode->name : null);
        $this->releaseVersion($driveFileVersion, $effectiveBlob, $workingCopyKey);
        if ($effectiveBlob->state === DriveBlob::STATE_BLOCKED) {
            $driveNode = $this->getDriveNodesService()->find((int)$driveFileVersion->driveNodeId, false);
            if ($driveNode instanceof DriveNode) {
                $this->getDriveChangesService()->recordChange($driveNode, DriveChangeEvent::EVENT_BLOCKED, (int)$driveFileVersion->id, $this->getDriveNodesService()->buildPath($driveNode), workingCopyKey: $workingCopyKey);
            }
        }
        return $effectiveBlob;
    }

    /**
     * A person's upload arrived: completes it, writes the file (a new version on top of `$expectedLatestVersionId`, or
     * on top of whatever is there with `$replaceExisting`) and runs the ingest. A conflict leaves the blob for the sweep.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function writeUploadedFile(DriveBlob $driveBlob, DriveNode $folderNode, string $name, ?string $comment, ?int $expectedLatestVersionId, bool $replaceExisting, ?bool $asyncIngest = null): DriveFileWriteResult
    {
        $driveSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$folderNode->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        if (!$folderNode->isFolder() || $driveBlob->driveSpaceId !== $folderNode->driveSpaceId) {
            throw new BadRequestException('The upload belongs to another space, or the target is no folder.');
        }
        $driveBlob = $this->getDriveBlobsService()->completeUpload($driveBlob);
        if ($this->getDriveTypePoliciesService()->buildTypePolicyForSpace($driveSpace, $this->getDriveSpacesService()->getCurrentAudience())->isRestricted()) {
            $name = DriveTypePolicy::sanitizeFileName($name);
        }
        $driveFileWrite = new DriveFileWrite();
        $driveFileWrite->driveBlobId = (int)$driveBlob->id;
        $driveFileWrite->comment = $comment;
        $driveFileWrite->origin = DriveNode::ORIGIN_UPLOAD;
        $driveFileWrite->isContentUntrusted = $driveSpace->isShared();
        $driveFileWrite->expectedLatestVersionId = $expectedLatestVersionId;
        $driveFileWrite->ignoresExpectedVersion = $replaceExisting;
        $authAccount = AuthService::instance()->getAccount();
        $driveFileWrite->authorAccountId = $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
        $driveFileWriteResult = $this->writeFile($driveSpace, $folderNode, $name, $driveFileWrite);
        if ($driveFileWriteResult->driveFileVersion !== null) {
            $this->ingestVersion($driveFileWriteResult->driveFileVersion, !(bool)$folderNode->isSystem, null, $asyncIngest);
            $freshNode = $this->getDriveNodesService()->find((int)$driveFileWriteResult->driveNode->id, false);
            $driveFileWriteResult->driveNode = $freshNode instanceof DriveNode ? $freshNode : $driveFileWriteResult->driveNode;
        }
        return $driveFileWriteResult;
    }

    /**
     * Scans a blob whose scan failed again and releases (or blocks) every version on it. Answers the blob's state.
     *
     * @throws InternalErrorException
     */
    public function rescanBlob(DriveBlob $driveBlob): string
    {
        $effectiveBlob = $this->getDriveBlobsService()->ingestBlob($driveBlob);
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveBlobId IN (:driveBlobIds)")
            ->setParameter('driveBlobIds', array_values(array_unique([(int)$driveBlob->id, (int)$effectiveBlob->id])));
        $driveFileVersions = $repoClass->find($queryBuilder, false);
        foreach (($driveFileVersions instanceof DriveFileVersions ? $driveFileVersions->getElements() : []) as $driveFileVersion) {
            $this->releaseVersion($driveFileVersion, $effectiveBlob);
        }
        return (string)$effectiveBlob->state;
    }

    /**
     * Writes a file from content the server holds (a seed, an import): the bytes go to the store from PHP, then the
     * file is written at the path (folders created) and ingested. Small content only (it is in memory).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function writeFileContent(DriveSpace $driveSpace, string $path, string $content, ?string $contentType = null, ?string $comment = null, string $origin = DriveNode::ORIGIN_UPLOAD): DriveFileWriteResult
    {
        $pathSegments = DriveNodesService::splitPath($path);
        $fileName = (string)array_pop($pathSegments);
        $folderNode = $this->getDriveNodesService()->findOrCreateFolderPath($driveSpace, implode('/', $pathSegments));
        $driveUploadRequest = new DriveUploadRequest();
        $driveUploadRequest->sizeBytes = strlen($content);
        $driveUploadRequest->contentType = $contentType;
        $driveUploadReservation = $this->getDriveBlobsService()->reserveUpload($driveSpace, $driveUploadRequest);
        $driveBlob = $this->getDriveBlobsService()->find($driveUploadReservation->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob) {
            throw new InternalErrorException('The reserved blob is missing.');
        }
        if ($driveBlob->state === DriveBlob::STATE_UPLOADING) {
            if ($driveBlob->multipartUploadId !== null) {
                $this->getDriveBlobsService()->getObjectStorage()->abortMultipartUpload((string)$driveBlob->storageKey, $driveBlob->multipartUploadId);
                $driveBlob->multipartUploadId = null;
                $driveBlob->updatePartialIgnoringRights('multipartUploadId');
            }
            $this->getDriveBlobsService()->getObjectStorage()->putObjectIfAbsent((string)$driveBlob->storageKey, $content, $contentType);
        }
        $existingNode = $this->getDriveNodesService()->findChildByName($folderNode, $fileName);
        // the content is wanted at once (a seed): its ingest runs here
        return $this->writeUploadedFile($driveBlob, $folderNode, $fileName, $comment, $existingNode?->latestVersionId, $existingNode !== null, false);
    }

    /** The versions of a file, newest first */
    public function findVersionsOfNode(DriveNode $driveNode, int $limit = 50): DriveFileVersions
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveNodeId = :driveNodeId")
            ->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->setParameter('driveNodeId', (int)$driveNode->id)
            ->setParameter('driveSpaceId', (int)$driveNode->driveSpaceId)
            ->orderBy("{$alias}.versionNumber", 'DESC')
            ->setMaxResults(max(1, min(500, $limit)));
        $driveFileVersions = $repoClass->find($queryBuilder, false);
        return $driveFileVersions instanceof DriveFileVersions ? $driveFileVersions : new DriveFileVersions();
    }

    /**
     * Versions by id (a list's batch lookup), under the caller's rights; missing ids are left out.
     *
     * @param int[] $driveFileVersionIds
     */
    public function findVersionsByIds(array $driveFileVersionIds): DriveFileVersions
    {
        $driveFileVersionIds = array_values(array_unique(array_filter(array_map('intval', $driveFileVersionIds))));
        if ($driveFileVersionIds === []) {
            return new DriveFileVersions();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.id IN (:driveFileVersionIds)")->setParameter('driveFileVersionIds', $driveFileVersionIds);
        $driveFileVersions = $repoClass->find($queryBuilder, false);
        return $driveFileVersions instanceof DriveFileVersions ? $driveFileVersions : new DriveFileVersions();
    }

    /**
     * Makes an older version the newest again: a new version on top of the file's write head with the old version's
     * released content (no bytes are copied; the blob is shared, so the quota does not grow). Only released content
     * is restored — a version the scan blocked, or one still waiting for its scan, is refused.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function restoreVersion(DriveNode $driveNode, DriveFileVersion $driveFileVersion): DriveFileWriteResult
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        if (!$driveNode->isFile() || $driveNode->isTrashed()) {
            throw new BadRequestException('Only a live file has versions to restore.');
        }
        if ((int)$driveFileVersion->driveNodeId !== (int)$driveNode->id) {
            throw new BadRequestException(sprintf('Version %d is no version of this file.', (int)$driveFileVersion->id));
        }
        $driveBlob = $this->getDriveBlobsService()->find((int)$driveFileVersion->driveBlobId, false);
        $restoreRefusalReason = static::findRestoreRefusalReason($driveBlob);
        if ($restoreRefusalReason !== null) {
            throw new BadRequestException(sprintf('Version %d cannot be restored: %s.', (int)$driveFileVersion->versionNumber, $restoreRefusalReason));
        }
        $driveFileWrite = new DriveFileWrite();
        $driveFileWrite->driveBlobId = (int)$driveBlob->id;
        $driveFileWrite->comment = sprintf(static::RESTORED_VERSION_COMMENT, (int)$driveFileVersion->versionNumber);
        $driveFileWrite->origin = DriveNode::ORIGIN_UPLOAD;
        $driveFileWrite->isContentUntrusted = (bool)$driveFileVersion->isContentUntrusted;
        $driveFileWrite->copiedFromVersionId = (int)$driveFileVersion->id;
        $driveFileWrite->ignoresExpectedVersion = true;
        $authAccount = AuthService::instance()->getAccount();
        $driveFileWrite->authorAccountId = $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
        $driveFileWriteResult = $this->writeVersionOfNode($driveNode, $driveBlob, $driveFileWrite);
        if ($driveFileWriteResult->driveFileVersion !== null) {
            // the content is released already: the read head moves at once
            $this->releaseVersion($driveFileWriteResult->driveFileVersion, $driveBlob);
            $freshNode = $this->getDriveNodesService()->find((int)$driveNode->id, false);
            $driveFileWriteResult->driveNode = $freshNode instanceof DriveNode ? $freshNode : $driveFileWriteResult->driveNode;
        }
        return $driveFileWriteResult;
    }

    /** Why a version's content cannot be restored; null when it can (released by the scan) */
    public static function findRestoreRefusalReason(?DriveBlob $driveBlob): ?string
    {
        if (!$driveBlob instanceof DriveBlob) {
            return 'its content is gone';
        }
        return match ($driveBlob->state) {
            DriveBlob::STATE_RELEASED => null,
            DriveBlob::STATE_BLOCKED => 'the malware scan blocked its content',
            DriveBlob::STATE_PURGING => 'its content is being deleted',
            default => 'its content is not released (it waits for its scan, or the scan could not run)',
        };
    }

    /**
     * The version history of a file for a file manager: scan status of each blob, author names, which version the
     * file shows and which is its newest, and whether each can be restored.
     */
    public function buildVersionListItems(DriveNode $driveNode, DriveFileVersions $driveFileVersions): DriveFileVersionListItems
    {
        $driveBlobIds = [];
        $authorAccountIds = [];
        foreach ($driveFileVersions->getElements() as $driveFileVersion) {
            $driveBlobIds[] = (int)$driveFileVersion->driveBlobId;
            if ($driveFileVersion->authorAccountId !== null) {
                $authorAccountIds[] = (int)$driveFileVersion->authorAccountId;
            }
        }
        $driveBlobsById = [];
        foreach ($this->getDriveBlobsService()->findBlobsByIds($driveBlobIds)->getElements() as $driveBlob) {
            $driveBlobsById[(int)$driveBlob->id] = $driveBlob;
        }
        /** @var DriveActorNamesService $driveActorNamesService */
        $driveActorNamesService = DDDService::instance()->getService(DriveActorNamesService::class);
        $accountDisplayNames = $driveActorNamesService->findActorDisplayNames($authorAccountIds, $this->getDriveSpacesService()->getCurrentAudience());
        $driveFileVersionListItems = new DriveFileVersionListItems();
        foreach ($driveFileVersions->getElements() as $driveFileVersion) {
            $driveBlob = $driveBlobsById[(int)$driveFileVersion->driveBlobId] ?? null;
            $driveFileVersionListItem = new DriveFileVersionListItem();
            $driveFileVersionListItem->id = (int)$driveFileVersion->id;
            $driveFileVersionListItem->driveNodeId = (int)$driveFileVersion->driveNodeId;
            $driveFileVersionListItem->versionNumber = (int)$driveFileVersion->versionNumber;
            $driveFileVersionListItem->sizeBytes = $driveFileVersion->sizeBytes;
            $driveFileVersionListItem->contentType = $driveBlob?->contentType ?? $driveFileVersion->contentType;
            $driveFileVersionListItem->comment = $driveFileVersion->comment;
            $driveFileVersionListItem->origin = $driveFileVersion->origin;
            $driveFileVersionListItem->authorAccountId = $driveFileVersion->authorAccountId;
            $driveFileVersionListItem->authorName = $driveFileVersion->authorAccountId !== null ? ($accountDisplayNames[(int)$driveFileVersion->authorAccountId] ?? null) : null;
            $driveFileVersionListItem->createdAt = $driveFileVersion->getCreatedTime();
            $driveFileVersionListItem->scanStatus = $driveBlob?->findScanStatus();
            $driveFileVersionListItem->scanDetail = $driveBlob?->stateReason;
            $driveFileVersionListItem->isCurrent = (int)$driveFileVersion->id === (int)$driveNode->currentVersionId;
            $driveFileVersionListItem->isLatest = (int)$driveFileVersion->id === (int)$driveNode->latestVersionId;
            $driveFileVersionListItem->isRestorable = !$driveFileVersionListItem->isLatest && static::findRestoreRefusalReason($driveBlob) === null;
            $driveFileVersionListItems->add($driveFileVersionListItem);
        }
        return $driveFileVersionListItems;
    }

    /**
     * @throws InternalErrorException
     */
    protected function writeNewFile(DriveSpace $driveSpace, DriveNode $folderNode, string $name, DriveBlob $driveBlob, DriveFileWrite $driveFileWrite): DriveFileWriteResult
    {
        $driveNode = DriveNode::newInstance();
        $driveNode->driveSpaceId = (int)$driveSpace->id;
        $driveNode->parentNodeId = (int)$folderNode->id;
        $driveNode->nodeType = DriveNode::NODE_TYPE_FILE;
        $driveNode->name = $name;
        $driveNode->nameSlot = $name;
        $driveNode->nameFolded = DriveNode::foldName($name);
        $driveNode->isSystem = (bool)$folderNode->isSystem;
        $driveNode->origin = $driveFileWrite->origin;
        $driveNode->isContentUntrusted = $driveFileWrite->isContentUntrusted;
        $driveNode->createdByAccountId = $driveFileWrite->authorAccountId;
        $driveNode->copiedFromNodeId = $driveFileWrite->copiedFromNodeId;
        $driveNode->copiedFromVersionId = $driveFileWrite->copiedFromVersionId;
        $driveNode->contentType = $driveBlob->contentType;
        $driveNode->sizeBytes = (int)$driveBlob->sizeBytes;
        $driveNode->versionNumber = 0;
        try {
            /** @var DriveNode $driveNode */
            $driveNode = $driveNode->update();
        } catch (UniqueConstraintViolationException) {
            $takenNode = $this->getDriveNodesService()->findChildByName($folderNode, $name);
            return $this->buildConflict($takenNode, $takenNode?->latestVersionId, sprintf('"%s" was written meanwhile', $name));
        }
        $driveFileVersion = $this->storeVersion($this->buildVersion($driveNode, $driveBlob, $driveFileWrite, 1));
        $this->swapWriteHead($driveNode, null, $driveFileVersion, $driveBlob);
        $this->getDriveNodesService()->adjustChildCount((int)$folderNode->id, 1);
        $this->getDriveSpacesService()->adjustCounters((int)$driveSpace->id, 1, 0);
        $path = $this->getDriveNodesService()->buildPath($driveNode);
        $eventType = $driveFileWrite->copiedFromNodeId !== null ? DriveChangeEvent::EVENT_COPIED_IN : DriveChangeEvent::EVENT_CREATED;
        $this->getDriveChangesService()->recordChange($driveNode, $eventType, (int)$driveFileVersion->id, $path, workingCopyKey: $driveFileWrite->workingCopyKey, onBehalfOfAccountId: $driveFileWrite->onBehalfOfAccountId);
        $driveFileWriteResult = new DriveFileWriteResult();
        $driveFileWriteResult->driveNode = $driveNode;
        $driveFileWriteResult->driveFileVersion = $driveFileVersion;
        $driveFileWriteResult->isNewFile = true;
        return $driveFileWriteResult;
    }

    protected function buildVersion(DriveNode $driveNode, DriveBlob $driveBlob, DriveFileWrite $driveFileWrite, int $versionNumber): DriveFileVersion
    {
        $driveFileVersion = DriveFileVersion::newInstance();
        $driveFileVersion->driveSpaceId = (int)$driveNode->driveSpaceId;
        $driveFileVersion->driveNodeId = (int)$driveNode->id;
        $driveFileVersion->versionNumber = $versionNumber;
        $driveFileVersion->driveBlobId = (int)$driveBlob->id;
        $driveFileVersion->sizeBytes = (int)$driveBlob->sizeBytes;
        $driveFileVersion->contentType = $driveBlob->contentType;
        $driveFileVersion->sha256 = $driveBlob->sha256;
        $driveFileVersion->comment = $driveFileWrite->comment !== null ? mb_substr($driveFileWrite->comment, 0, 2000) : null;
        $driveFileVersion->origin = $driveFileWrite->origin;
        $driveFileVersion->isContentUntrusted = $driveFileWrite->isContentUntrusted;
        $driveFileVersion->authorAccountId = $driveFileWrite->authorAccountId;
        $driveFileVersion->onBehalfOfAccountId = $driveFileWrite->onBehalfOfAccountId;
        $driveFileVersion->conversationKey = $driveFileWrite->conversationKey;
        $driveFileVersion->copiedFromVersionId = $driveFileWrite->copiedFromVersionId;
        return $driveFileVersion;
    }

    protected function storeVersion(DriveFileVersion $driveFileVersion): DriveFileVersion
    {
        /** @var DriveFileVersion $storedVersion */
        $storedVersion = $driveFileVersion->insert();
        return $storedVersion;
    }

    /** Moves the write head from the base to the new version in one statement; false when the head moved meanwhile */
    protected function swapWriteHead(DriveNode $driveNode, ?int $baseVersionId, DriveFileVersion $driveFileVersion, DriveBlob $driveBlob): bool
    {
        $queryBuilder = DBDriveNode::createQueryBuilder()
            ->update(DBDriveNode::BASE_ORM_MODEL, 'node')
            ->set('node.latestVersionId', ':versionId')
            ->set('node.versionNumber', ':versionNumber')
            ->set('node.sizeBytes', ':sizeBytes')
            ->set('node.contentType', ':contentType')
            ->set('node.sha256', ':sha256')
            ->where('node.id = :nodeId')
            ->setParameter('versionId', (int)$driveFileVersion->id)
            ->setParameter('versionNumber', (int)$driveFileVersion->versionNumber)
            ->setParameter('sizeBytes', (int)$driveBlob->sizeBytes)
            ->setParameter('contentType', $driveBlob->contentType)
            ->setParameter('sha256', $driveBlob->sha256)
            ->setParameter('nodeId', (int)$driveNode->id);
        if ($baseVersionId === null) {
            $queryBuilder->andWhere('node.latestVersionId IS NULL');
        } else {
            $queryBuilder->andWhere('node.latestVersionId = :baseVersionId')->setParameter('baseVersionId', $baseVersionId);
        }
        if ((int)$queryBuilder->getQuery()->execute() !== 1) {
            return false;
        }
        if ($driveBlob->state === DriveBlob::STATE_RELEASED) {
            DBDriveNode::createQueryBuilder()
                ->update(DBDriveNode::BASE_ORM_MODEL, 'node')
                ->set('node.currentVersionId', ':versionId')
                ->where('node.id = :nodeId')
                ->setParameter('versionId', (int)$driveFileVersion->id)
                ->setParameter('nodeId', (int)$driveNode->id)
                ->getQuery()->execute();
            $driveNode->currentVersionId = (int)$driveFileVersion->id;
        }
        $driveNode->latestVersionId = (int)$driveFileVersion->id;
        $driveNode->versionNumber = (int)$driveFileVersion->versionNumber;
        $driveNode->sizeBytes = (int)$driveBlob->sizeBytes;
        $driveNode->contentType = $driveBlob->contentType;
        $driveNode->sha256 = $driveBlob->sha256;
        return true;
    }

    protected function deleteVersionRow(DriveFileVersion $driveFileVersion): void
    {
        try {
            DBDriveFileVersion::createQueryBuilder()
                ->delete(DBDriveFileVersion::BASE_ORM_MODEL, 'version')
                ->where('version.id = :versionId')
                ->setParameter('versionId', (int)$driveFileVersion->id)
                ->getQuery()->execute();
        } catch (Throwable) {
            // an orphaned version row is harmless: no node points at it, the maintenance purges it
        }
    }

    protected function buildConflict(?DriveNode $driveNode, ?int $conflictVersionId, string $conflictReason): DriveFileWriteResult
    {
        $driveFileWriteResult = new DriveFileWriteResult();
        $driveFileWriteResult->driveNode = $driveNode;
        $driveFileWriteResult->isConflict = true;
        $driveFileWriteResult->conflictVersionId = $conflictVersionId;
        $driveFileWriteResult->conflictReason = $conflictReason;
        return $driveFileWriteResult;
    }

    protected function getDriveTypePoliciesService(): DriveTypePoliciesService
    {
        /** @var DriveTypePoliciesService $driveTypePoliciesService */
        $driveTypePoliciesService = DDDService::instance()->getService(DriveTypePoliciesService::class);
        return $driveTypePoliciesService;
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

    protected function getDriveChangesService(): DriveChangesService
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService;
    }
}
