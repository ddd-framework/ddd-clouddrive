<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWrite;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolution;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolutions;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedChange;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedChanges;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedUpload;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveStagedUploads;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyChanges;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntries;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntry;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyFile;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyFiles;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopy;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopyEntries;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopyEntry;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * A sandbox works on a space through a working copy, like git without git: the runner keeps the base version of
 * every path, saves changed content after each call (`stageChanges()`, staged blobs, nothing visible yet), and a
 * commit turns the saved changes into versions with a compare and swap on each node's write head. A pull
 * (`findChangesSince()`) hands over what others changed. A conflict never overwrites anything: the entry stays saved
 * with its reason until it is resolved (MINE, THEIRS, BOTH).
 *
 * @method DriveWorkingCopy|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DBDriveWorkingCopy getEntityRepoClassInstance()
 */
class DriveWorkingCopiesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DriveWorkingCopy::class;

    public const int MAX_BASE_MAP_FILES = 50000;
    public const int MAX_STAGED_CHANGES = 1000;

    /** The working copy of a sandbox on a space, created on first use; needs the write right */
    public function findOrCreateWorkingCopy(DriveSpace $driveSpace, string $workingCopyKey): DriveWorkingCopy
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
        $driveWorkingCopy = $this->findWorkingCopy($driveSpace, $workingCopyKey);
        if ($driveWorkingCopy !== null) {
            return $driveWorkingCopy;
        }
        $driveWorkingCopy = DriveWorkingCopy::newInstance();
        $driveWorkingCopy->driveSpaceId = (int)$driveSpace->id;
        $driveWorkingCopy->workingCopyKey = $workingCopyKey;
        $driveWorkingCopy->changeCursor = $this->getDriveChangesService()->findNewestChangeId((int)$driveSpace->id);
        try {
            /** @var DriveWorkingCopy $driveWorkingCopy */
            $driveWorkingCopy = $driveWorkingCopy->insert();
        } catch (Throwable $throwable) {
            $existingWorkingCopy = $this->findWorkingCopy($driveSpace, $workingCopyKey);
            if ($existingWorkingCopy === null) {
                throw $throwable;
            }
            return $existingWorkingCopy;
        }
        return $driveWorkingCopy;
    }

    /** The working copy of the space changed last (a sandbox that did not name its key) */
    public function findNewestWorkingCopy(DriveSpace $driveSpace): ?DriveWorkingCopy
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->orderBy("{$alias}.updated", 'DESC')
            ->addOrderBy("{$alias}.id", 'DESC')
            ->setMaxResults(1);
        $driveWorkingCopy = $repoClass->find($queryBuilder, false);
        return $driveWorkingCopy instanceof DriveWorkingCopy ? $driveWorkingCopy : null;
    }

    public function findWorkingCopy(DriveSpace $driveSpace, string $workingCopyKey): ?DriveWorkingCopy
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.workingCopyKey = :workingCopyKey")
            ->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->setParameter('workingCopyKey', $workingCopyKey);
        $driveWorkingCopy = $repoClass->find($queryBuilder, false);
        return $driveWorkingCopy instanceof DriveWorkingCopy ? $driveWorkingCopy : null;
    }

    /**
     * The base map the runner gets at claim: every live file of the space at its newest written version, overlaid with
     * the saved entries (so a lost runner gets its uncommitted state back), and the cursor to pull from afterwards.
     */
    public function buildBaseMap(DriveWorkingCopy $driveWorkingCopy): DriveWorkingCopyFiles
    {
        $driveSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveWorkingCopy->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $driveWorkingCopyFiles = new DriveWorkingCopyFiles();
        $rootNode = $this->getDriveNodesService()->findRootNode($driveSpace);
        if ($rootNode !== null) {
            $this->addFilesBelowFolder($driveWorkingCopyFiles, $rootNode, '');
        }
        foreach ($this->findEntries($driveWorkingCopy)->getElements() as $driveWorkingCopyEntry) {
            $previousFile = $driveWorkingCopyEntry->previousPath !== null ? $driveWorkingCopyFiles->findByPath($driveWorkingCopyEntry->previousPath) : null;
            if ($previousFile !== null && $driveWorkingCopyEntry->changeKind === DriveWorkingCopyEntry::CHANGE_MOVED) {
                $driveWorkingCopyFiles->remove($previousFile);
            }
            $driveWorkingCopyFile = $driveWorkingCopyFiles->findByPath((string)$driveWorkingCopyEntry->path) ?? new DriveWorkingCopyFile();
            $driveWorkingCopyFile->path = (string)$driveWorkingCopyEntry->path;
            $driveWorkingCopyFile->previousPath = $driveWorkingCopyEntry->previousPath;
            $driveWorkingCopyFile->driveNodeId ??= $driveWorkingCopyEntry->driveNodeId;
            $driveWorkingCopyFile->versionId = $driveWorkingCopyEntry->baseVersionId ?? $driveWorkingCopyFile->versionId;
            $driveWorkingCopyFile->changeKind = $driveWorkingCopyEntry->changeKind;
            $driveWorkingCopyFile->stagedDriveBlobId = $driveWorkingCopyEntry->stagedDriveBlobId;
            $driveWorkingCopyFile->conflictReason = $driveWorkingCopyEntry->conflictReason;
            $driveWorkingCopyFile->conflictVersionId = $driveWorkingCopyEntry->conflictVersionId;
            $driveWorkingCopyFiles->add($driveWorkingCopyFile);
        }
        return $driveWorkingCopyFiles;
    }

    /**
     * Saves changes of the sandbox: one entry per path (replacing an earlier saved change of the path) and, for new
     * content, a staged blob with its upload. Content the space knows (same SHA-256) is not uploaded again.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function stageChanges(DriveWorkingCopy $driveWorkingCopy, DriveStagedChanges $driveStagedChanges): DriveStagedUploads
    {
        if ($driveStagedChanges->count() > self::MAX_STAGED_CHANGES) {
            throw new BadRequestException(sprintf('At most %d changes are saved per call.', self::MAX_STAGED_CHANGES));
        }
        $driveSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveWorkingCopy->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $driveStagedUploads = new DriveStagedUploads();
        foreach ($driveStagedChanges->getElements() as $driveStagedChange) {
            $path = implode('/', DriveNodesService::splitPath($driveStagedChange->path));
            if ($path === '' || !in_array($driveStagedChange->changeKind, DriveWorkingCopyEntry::CHANGES, true)) {
                throw new BadRequestException(sprintf('"%s": a saved change needs a path and a kind of %s.', $driveStagedChange->path, implode(', ', DriveWorkingCopyEntry::CHANGES)));
            }
            if ($this->isExcludedPath($path)) {
                continue;
            }
            $driveWorkingCopyEntry = $this->findEntryByPath($driveWorkingCopy, $path) ?? DriveWorkingCopyEntry::newInstance();
            $driveWorkingCopyEntry->driveWorkingCopyId = (int)$driveWorkingCopy->id;
            $driveWorkingCopyEntry->driveSpaceId = (int)$driveSpace->id;
            $driveWorkingCopyEntry->path = $path;
            $driveWorkingCopyEntry->changeKind = $driveStagedChange->changeKind;
            $driveWorkingCopyEntry->previousPath = $driveStagedChange->previousPath !== null ? implode('/', DriveNodesService::splitPath($driveStagedChange->previousPath)) : null;
            $driveWorkingCopyEntry->conversationKey = $driveStagedChange->conversationKey ?? $driveWorkingCopyEntry->conversationKey;
            $driveWorkingCopyEntry->conflictReason = null;
            $driveWorkingCopyEntry->conflictVersionId = null;
            if (!isset($driveWorkingCopyEntry->id)) {
                $baseNode = $this->getDriveNodesService()->findNodeByPath($driveSpace, $driveWorkingCopyEntry->previousPath ?? $path);
                $driveWorkingCopyEntry->driveNodeId = $baseNode !== null && $baseNode->isFile() ? (int)$baseNode->id : null;
                $driveWorkingCopyEntry->baseVersionId = $driveStagedChange->baseVersionId ?? ($baseNode?->isFile() ? $baseNode->latestVersionId : null);
            }
            $driveWorkingCopyEntry->stagedDriveBlobId = null;
            if (in_array($driveStagedChange->changeKind, [DriveWorkingCopyEntry::CHANGE_ADDED, DriveWorkingCopyEntry::CHANGE_MODIFIED, DriveWorkingCopyEntry::CHANGE_MOVED], true)
                && ($driveStagedChange->sha256 !== null || $driveStagedChange->changeKind !== DriveWorkingCopyEntry::CHANGE_MOVED)
            ) {
                $driveUploadRequest = new DriveUploadRequest();
                $driveUploadRequest->sizeBytes = $driveStagedChange->sizeBytes;
                $driveUploadRequest->contentType = $driveStagedChange->contentType;
                $driveUploadRequest->contentMd5 = $driveStagedChange->contentMd5;
                $driveUploadRequest->sha256 = $driveStagedChange->sha256 !== null ? strtolower($driveStagedChange->sha256) : null;
                $driveUploadReservation = $this->getDriveBlobsService()->reserveUpload($driveSpace, $driveUploadRequest);
                $driveWorkingCopyEntry->stagedDriveBlobId = $driveUploadReservation->driveBlobId;
                $driveStagedUpload = new DriveStagedUpload();
                $driveStagedUpload->path = $path;
                $driveStagedUpload->reservation = $driveUploadReservation;
                $driveStagedUploads->add($driveStagedUpload);
            }
            $driveWorkingCopyEntry->update();
        }
        return $driveStagedUploads;
    }

    /**
     * Drops saved changes the sandbox reverted (the file equals its base again).
     *
     * @param string[] $paths
     */
    public function discardChanges(DriveWorkingCopy $driveWorkingCopy, array $paths): void
    {
        foreach ($paths as $path) {
            $driveWorkingCopyEntry = $this->findEntryByPath($driveWorkingCopy, (string)$path);
            if ($driveWorkingCopyEntry !== null) {
                $this->deleteEntry($driveWorkingCopyEntry);
            }
        }
    }

    /**
     * Commits saved changes: the given paths, or every entry (of one conversation when `$conversationKey` is given).
     * Each path is its own compare and swap; a conflict leaves that entry saved with its reason, the rest is committed.
     *
     * @param string[]|null $paths
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function commitChanges(
        DriveWorkingCopy $driveWorkingCopy,
        string $message,
        ?array $paths = null,
        ?DriveCommitResolutions $driveCommitResolutions = null,
        ?string $conversationKey = null,
        string $origin = DriveNode::ORIGIN_AGENT
    ): DriveCommitResult {
        $driveSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveWorkingCopy->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $this->getDriveWorkingCopyListenersService()->notifyBeforeWorkingCopyRead($driveWorkingCopy);
        $driveCommitResult = new DriveCommitResult();
        $driveCommitResult->committedFiles = new DriveWorkingCopyFiles();
        $driveCommitResult->conflictedFiles = new DriveWorkingCopyFiles();
        $wantedPaths = $paths !== null ? array_flip(array_map('strval', $paths)) : null;
        foreach ($this->findEntries($driveWorkingCopy)->getElements() as $driveWorkingCopyEntry) {
            if ($wantedPaths !== null && !isset($wantedPaths[(string)$driveWorkingCopyEntry->path])) {
                continue;
            }
            if ($wantedPaths === null && $conversationKey !== null && $driveWorkingCopyEntry->conversationKey !== null && $driveWorkingCopyEntry->conversationKey !== $conversationKey) {
                continue;
            }
            $driveCommitResolution = $driveCommitResolutions?->findByPath((string)$driveWorkingCopyEntry->path);
            try {
                $this->commitEntry($driveSpace, $driveWorkingCopy, $driveWorkingCopyEntry, $message, $driveCommitResolution, $origin, $driveCommitResult);
            } catch (BadRequestException|NotFoundException $refusal) {
                $driveCommitResult->refusedLines[] = $driveWorkingCopyEntry->path . ': ' . $refusal->getMessage();
            }
        }
        $this->getDriveWorkingCopyListenersService()->notifyAfterWorkingCopyCommitted($driveWorkingCopy, $driveCommitResult, $driveCommitResolutions);
        return $driveCommitResult;
    }

    /**
     * The files others changed in the space since the working copy's cursor, in their current state (a removed flag
     * for trashed or moved-away files). The runner applies them and acknowledges the cursor
     * ({@see acknowledgeChanges()}).
     */
    public function findChangesSince(DriveWorkingCopy $driveWorkingCopy, ?int $afterChangeId = null, int $limit = DriveChangesService::MAX_CHANGES_PER_PAGE): DriveWorkingCopyChanges
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveWorkingCopy->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $fromCursor = $afterChangeId ?? (int)$driveWorkingCopy->changeCursor;
        $driveChangeEvents = $this->getDriveChangesService()->findChangesSince((int)$driveWorkingCopy->driveSpaceId, $fromCursor, $limit + 1, null);
        $driveWorkingCopyChanges = new DriveWorkingCopyChanges();
        $driveWorkingCopyChanges->fromCursor = $fromCursor;
        $driveWorkingCopyChanges->toCursor = $fromCursor;
        $driveWorkingCopyChanges->changedFiles = new DriveWorkingCopyFiles();
        $changedNodeIds = [];
        $previousPathsByNodeId = [];
        $eventCount = 0;
        foreach ($driveChangeEvents->getElements() as $driveChangeEvent) {
            if ($eventCount >= $limit) {
                $driveWorkingCopyChanges->hasMore = true;
                break;
            }
            $eventCount++;
            $driveWorkingCopyChanges->toCursor = (int)$driveChangeEvent->id;
            if ($driveChangeEvent->workingCopyKey === $driveWorkingCopy->workingCopyKey || $driveChangeEvent->driveNodeId === null) {
                continue;
            }
            if ($driveChangeEvent->eventType === DriveChangeEvent::EVENT_READ_BY_AGENT) {
                continue;
            }
            $changedNodeIds[(int)$driveChangeEvent->driveNodeId] = true;
            if ($driveChangeEvent->previousPath !== null && !isset($previousPathsByNodeId[(int)$driveChangeEvent->driveNodeId])) {
                $previousPathsByNodeId[(int)$driveChangeEvent->driveNodeId] = $driveChangeEvent->previousPath;
            }
        }
        foreach (array_keys($changedNodeIds) as $changedNodeId) {
            $driveNode = $this->getDriveNodesService()->find($changedNodeId, false);
            if (!$driveNode instanceof DriveNode || $driveNode->isRoot()) {
                continue;
            }
            $driveWorkingCopyFile = new DriveWorkingCopyFile();
            $driveWorkingCopyFile->driveNodeId = (int)$driveNode->id;
            $driveWorkingCopyFile->path = $this->getDriveNodesService()->buildPath($driveNode);
            $driveWorkingCopyFile->previousPath = $previousPathsByNodeId[$changedNodeId] ?? null;
            $driveWorkingCopyFile->isRemoved = $driveNode->isTrashed();
            $driveWorkingCopyFile->isSystem = (bool)$driveNode->isSystem;
            if ($driveNode->isFile()) {
                $this->fillFileFacts($driveWorkingCopyFile, $driveNode);
            } elseif (!$driveNode->isFolder()) {
                continue;
            }
            $driveWorkingCopyChanges->changedFiles->add($driveWorkingCopyFile);
        }
        return $driveWorkingCopyChanges;
    }

    /** The runner applied the changes up to the cursor */
    public function acknowledgeChanges(DriveWorkingCopy $driveWorkingCopy, int $toCursor): void
    {
        if ($toCursor <= (int)$driveWorkingCopy->changeCursor) {
            return;
        }
        $driveWorkingCopy->changeCursor = $toCursor;
        $driveWorkingCopy->updatePartialIgnoringRights('changeCursor');
    }

    /**
     * The saved entries after the host flushed its live sandbox ({@see DriveWorkingCopyListener::beforeWorkingCopyRead()}):
     * what a status shows.
     */
    public function findCurrentEntries(DriveWorkingCopy $driveWorkingCopy): DriveWorkingCopyEntries
    {
        $this->getDriveWorkingCopyListenersService()->notifyBeforeWorkingCopyRead($driveWorkingCopy);
        return $this->findEntries($driveWorkingCopy);
    }

    /** The saved entries of a working copy, by path (as saved; {@see findCurrentEntries()} flushes the sandbox first) */
    public function findEntries(DriveWorkingCopy $driveWorkingCopy): DriveWorkingCopyEntries
    {
        $repoClass = new DBDriveWorkingCopyEntries();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveWorkingCopyId = :driveWorkingCopyId")
            ->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->setParameter('driveWorkingCopyId', (int)$driveWorkingCopy->id)
            ->setParameter('driveSpaceId', (int)$driveWorkingCopy->driveSpaceId)
            ->orderBy("{$alias}.path", 'ASC');
        $driveWorkingCopyEntries = $repoClass->find($queryBuilder, false);
        return $driveWorkingCopyEntries instanceof DriveWorkingCopyEntries ? $driveWorkingCopyEntries : new DriveWorkingCopyEntries();
    }

    public function findEntryByPath(DriveWorkingCopy $driveWorkingCopy, string $path): ?DriveWorkingCopyEntry
    {
        $repoClass = new DBDriveWorkingCopyEntry();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveWorkingCopyId = :driveWorkingCopyId")
            ->andWhere("{$alias}.path = :path")
            ->setParameter('driveWorkingCopyId', (int)$driveWorkingCopy->id)
            ->setParameter('path', $path);
        $driveWorkingCopyEntry = $repoClass->find($queryBuilder, false);
        return $driveWorkingCopyEntry instanceof DriveWorkingCopyEntry && $driveWorkingCopyEntry->path === $path ? $driveWorkingCopyEntry : null;
    }

    /** True for a path the working copy never commits (`.venv`, `node_modules`, `.git`, …) */
    public function isExcludedPath(string $path): bool
    {
        $excludedNames = Config::get('CloudDrive.settings.workingCopyExcludedNames');
        $excludedNames = is_array($excludedNames) ? array_flip($excludedNames) : [];
        foreach (explode('/', $path) as $pathSegment) {
            if (isset($excludedNames[$pathSegment])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    protected function commitEntry(
        DriveSpace $driveSpace,
        DriveWorkingCopy $driveWorkingCopy,
        DriveWorkingCopyEntry $driveWorkingCopyEntry,
        string $message,
        ?DriveCommitResolution $driveCommitResolution,
        string $origin,
        DriveCommitResult $driveCommitResult
    ): void {
        $path = (string)$driveWorkingCopyEntry->path;
        if ($driveCommitResolution?->resolution === DriveCommitResolution::RESOLUTION_THEIRS) {
            $this->deleteEntry($driveWorkingCopyEntry);
            return;
        }
        $baseNode = $driveWorkingCopyEntry->driveNodeId !== null ? $this->getDriveNodesService()->find((int)$driveWorkingCopyEntry->driveNodeId, false) : null;
        $baseNode = $baseNode instanceof DriveNode && $baseNode->driveSpaceId === $driveSpace->id ? $baseNode : null;
        $ignoresExpectedVersion = $driveCommitResolution?->resolution === DriveCommitResolution::RESOLUTION_MINE;

        if ($driveWorkingCopyEntry->changeKind === DriveWorkingCopyEntry::CHANGE_DELETED) {
            if ($baseNode === null || $baseNode->isTrashed()) {
                $this->deleteEntry($driveWorkingCopyEntry);
                return;
            }
            if (!$ignoresExpectedVersion && $baseNode->latestVersionId !== $driveWorkingCopyEntry->baseVersionId) {
                $this->markConflict($driveWorkingCopyEntry, 'the file has a newer version in the drive; deleting it would drop that version', $baseNode->latestVersionId, $driveCommitResult);
                return;
            }
            $this->getDriveNodesService()->trashNode($baseNode, $driveWorkingCopy->workingCopyKey);
            $this->addCommittedFile($driveCommitResult, $driveWorkingCopyEntry, $baseNode);
            $this->deleteEntry($driveWorkingCopyEntry);
            return;
        }

        $targetPath = $path;
        if ($driveCommitResolution?->resolution === DriveCommitResolution::RESOLUTION_BOTH) {
            $bothName = trim((string)$driveCommitResolution->bothName);
            if ($bothName === '' || DriveNode::findInvalidNameReason($bothName) !== null) {
                throw new BadRequestException('resolution "both" needs a valid file name for your version');
            }
            $targetPath = (dirname($path) !== '.' ? dirname($path) . '/' : '') . $bothName;
            $baseNode = null;
        }
        $targetPathSegments = DriveNodesService::splitPath($targetPath);
        $fileName = (string)array_pop($targetPathSegments);
        $folderPath = implode('/', $targetPathSegments);

        if ($baseNode !== null && $baseNode->isTrashed()) {
            if (!$ignoresExpectedVersion) {
                $this->markConflict($driveWorkingCopyEntry, 'the file was trashed in the drive', $baseNode->latestVersionId, $driveCommitResult);
                return;
            }
            $this->getDriveNodesService()->restoreNode($baseNode, $driveWorkingCopy->workingCopyKey);
        }

        $folderNode = $this->getDriveNodesService()->findOrCreateFolderPath($driveSpace, $folderPath, null, $driveWorkingCopy->workingCopyKey);
        if ($baseNode !== null && ($baseNode->parentNodeId !== $folderNode->id || $baseNode->name !== $fileName)) {
            $this->getDriveNodesService()->moveNode($baseNode, $folderNode, $fileName, $driveWorkingCopy->workingCopyKey);
        }

        if ($driveWorkingCopyEntry->stagedDriveBlobId === null) {
            // a move without new content
            if ($baseNode !== null) {
                $this->addCommittedFile($driveCommitResult, $driveWorkingCopyEntry, $baseNode);
            }
            $this->deleteEntry($driveWorkingCopyEntry);
            return;
        }
        $stagedBlob = $this->requireUploadedBlob((int)$driveWorkingCopyEntry->stagedDriveBlobId);

        $driveFileWrite = new DriveFileWrite();
        $driveFileWrite->driveBlobId = (int)$stagedBlob->id;
        $driveFileWrite->comment = $message;
        $driveFileWrite->origin = $origin;
        $driveFileWrite->isContentUntrusted = (bool)($baseNode?->isContentUntrusted ?? false);
        $driveFileWrite->expectedLatestVersionId = $baseNode !== null ? $driveWorkingCopyEntry->baseVersionId : null;
        $driveFileWrite->ignoresExpectedVersion = $ignoresExpectedVersion;
        $driveFileWrite->authorAccountId = $this->findActingAccountId();
        $driveFileWrite->conversationKey = $driveWorkingCopyEntry->conversationKey;
        $driveFileWrite->workingCopyKey = $driveWorkingCopy->workingCopyKey;
        $driveFileWriteResult = $baseNode !== null
            ? $this->getDriveVersionsService()->writeVersionOfNode($baseNode, $stagedBlob, $driveFileWrite)
            : $this->getDriveVersionsService()->writeFile($driveSpace, $folderNode, $fileName, $driveFileWrite);
        if ($driveFileWriteResult->isConflict) {
            $this->markConflict($driveWorkingCopyEntry, (string)$driveFileWriteResult->conflictReason, $driveFileWriteResult->conflictVersionId, $driveCommitResult);
            return;
        }
        if ($driveFileWriteResult->driveFileVersion !== null) {
            $isScanned = !($driveFileWriteResult->driveNode?->isSystem ?? false);
            $this->getDriveVersionsService()->ingestVersion($driveFileWriteResult->driveFileVersion, $isScanned, $driveWorkingCopy->workingCopyKey);
        } elseif ($driveFileWriteResult->isUnchanged && $stagedBlob->state !== DriveBlob::STATE_RELEASED) {
            $this->getDriveBlobsService()->markForPurge($stagedBlob);
        }
        $committedNode = $driveFileWriteResult->driveNode ?? $baseNode;
        if ($committedNode !== null) {
            $freshNode = $this->getDriveNodesService()->find((int)$committedNode->id, false);
            $this->addCommittedFile($driveCommitResult, $driveWorkingCopyEntry, $freshNode instanceof DriveNode ? $freshNode : $committedNode, $targetPath);
        }
        $this->deleteEntry($driveWorkingCopyEntry);
    }

    /**
     * The staged blob of an entry with its bytes in the store (completes its upload on first use).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    protected function requireUploadedBlob(int $driveBlobId): DriveBlob
    {
        $driveBlob = $this->getDriveBlobsService()->find($driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob) {
            throw new BadRequestException(sprintf('The saved content (blob %d) is gone: save the file again.', $driveBlobId));
        }
        if ($driveBlob->state === DriveBlob::STATE_UPLOADING) {
            $driveBlob = $this->getDriveBlobsService()->completeUpload($driveBlob, DriveBlob::STATE_STAGED);
        }
        if (in_array($driveBlob->state, [DriveBlob::STATE_FAILED, DriveBlob::STATE_PURGING], true)) {
            throw new BadRequestException(sprintf('The saved content (blob %d) failed: %s. Save the file again.', $driveBlobId, (string)$driveBlob->stateReason));
        }
        return $driveBlob;
    }

    protected function markConflict(DriveWorkingCopyEntry $driveWorkingCopyEntry, string $conflictReason, ?int $conflictVersionId, DriveCommitResult $driveCommitResult): void
    {
        $driveWorkingCopyEntry->conflictReason = mb_substr($conflictReason, 0, 1000);
        $driveWorkingCopyEntry->conflictVersionId = $conflictVersionId;
        $driveWorkingCopyEntry->updatePartialIgnoringRights('conflictReason', 'conflictVersionId');
        $driveWorkingCopyFile = new DriveWorkingCopyFile();
        $driveWorkingCopyFile->path = (string)$driveWorkingCopyEntry->path;
        $driveWorkingCopyFile->driveNodeId = $driveWorkingCopyEntry->driveNodeId;
        $driveWorkingCopyFile->versionId = $driveWorkingCopyEntry->baseVersionId;
        $driveWorkingCopyFile->changeKind = $driveWorkingCopyEntry->changeKind;
        $driveWorkingCopyFile->stagedDriveBlobId = $driveWorkingCopyEntry->stagedDriveBlobId;
        $driveWorkingCopyFile->conflictReason = $driveWorkingCopyEntry->conflictReason;
        $driveWorkingCopyFile->conflictVersionId = $conflictVersionId;
        $driveCommitResult->conflictedFiles->add($driveWorkingCopyFile);
    }

    protected function addCommittedFile(DriveCommitResult $driveCommitResult, DriveWorkingCopyEntry $driveWorkingCopyEntry, DriveNode $driveNode, ?string $path = null): void
    {
        $driveWorkingCopyFile = new DriveWorkingCopyFile();
        $driveWorkingCopyFile->path = $path ?? (string)$driveWorkingCopyEntry->path;
        $driveWorkingCopyFile->previousPath = $driveWorkingCopyEntry->previousPath;
        $driveWorkingCopyFile->changeKind = $driveWorkingCopyEntry->driveNodeId === null ? DriveWorkingCopyEntry::CHANGE_ADDED : $driveWorkingCopyEntry->changeKind;
        $driveWorkingCopyFile->driveNodeId = (int)$driveNode->id;
        $this->fillFileFacts($driveWorkingCopyFile, $driveNode);
        $driveCommitResult->committedFiles->add($driveWorkingCopyFile);
    }

    protected function fillFileFacts(DriveWorkingCopyFile $driveWorkingCopyFile, DriveNode $driveNode): void
    {
        $driveWorkingCopyFile->versionId = $driveNode->latestVersionId;
        $driveWorkingCopyFile->versionNumber = $driveNode->versionNumber;
        $driveWorkingCopyFile->sha256 = $driveNode->sha256;
        $driveWorkingCopyFile->sizeBytes = $driveNode->sizeBytes;
        $driveWorkingCopyFile->contentType = $driveNode->contentType;
        $driveWorkingCopyFile->isSystem = (bool)$driveNode->isSystem;
        if ($driveNode->latestVersionId !== null) {
            $driveFileVersion = $this->getDriveVersionsService()->find((int)$driveNode->latestVersionId, false);
            $driveWorkingCopyFile->driveBlobId = $driveFileVersion?->driveBlobId;
        }
    }

    protected function addFilesBelowFolder(DriveWorkingCopyFiles $driveWorkingCopyFiles, DriveNode $folderNode, string $folderPath): void
    {
        $cursor = null;
        do {
            $childNodes = $this->getDriveNodesService()->findChildren($folderNode, $cursor, DriveNodesService::MAX_LIST_LIMIT);
            $lastChildNode = null;
            foreach ($childNodes->getElements() as $childNode) {
                $lastChildNode = $childNode;
                if ($driveWorkingCopyFiles->count() >= self::MAX_BASE_MAP_FILES) {
                    return;
                }
                $childPath = ($folderPath !== '' ? $folderPath . '/' : '') . $childNode->name;
                if ($childNode->isFolder()) {
                    $this->addFilesBelowFolder($driveWorkingCopyFiles, $childNode, $childPath);
                    continue;
                }
                if (!$childNode->isFile() || $childNode->latestVersionId === null) {
                    continue;
                }
                $driveWorkingCopyFile = new DriveWorkingCopyFile();
                $driveWorkingCopyFile->path = $childPath;
                $driveWorkingCopyFile->driveNodeId = (int)$childNode->id;
                $this->fillFileFacts($driveWorkingCopyFile, $childNode);
                $driveWorkingCopyFiles->add($driveWorkingCopyFile);
            }
            $cursor = $lastChildNode !== null && $childNodes->count() >= DriveNodesService::MAX_LIST_LIMIT ? DriveNodesService::buildListCursor($lastChildNode) : null;
        } while ($cursor !== null);
    }

    protected function deleteEntry(DriveWorkingCopyEntry $driveWorkingCopyEntry): void
    {
        DBDriveWorkingCopyEntry::createQueryBuilder()
            ->delete(DBDriveWorkingCopyEntry::BASE_ORM_MODEL, 'entry')
            ->where('entry.id = :entryId')
            ->setParameter('entryId', (int)$driveWorkingCopyEntry->id)
            ->getQuery()->execute();
    }

    protected function findActingAccountId(): ?int
    {
        $authAccount = AuthService::instance()->getAccount();
        return $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
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

    protected function getDriveWorkingCopyListenersService(): DriveWorkingCopyListenersService
    {
        /** @var DriveWorkingCopyListenersService $driveWorkingCopyListenersService */
        $driveWorkingCopyListenersService = DDDService::instance()->getService(DriveWorkingCopyListenersService::class);
        return $driveWorkingCopyListenersService;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService;
    }
}
