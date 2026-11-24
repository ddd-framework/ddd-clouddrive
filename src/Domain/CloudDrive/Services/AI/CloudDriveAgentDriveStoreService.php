<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFile;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveListing;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveResult;
use DDD\Domain\AI\Services\Drives\AgentDriveStore;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWrite;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The cloud drive behind the agent's drive mounts (`ddd-agents`' {@see AgentDriveStore}): the agent space's own drive
 * and the spaces {@see DriveSpaceMountResolverService} mounts. Paths of a mount are paths below its space's root;
 * references are `drive/<node id>`. Everything runs under the rights of the account the call acts for.
 */
class CloudDriveAgentDriveStoreService extends Service implements AgentDriveStore
{
    public const string STORE_NAME = 'clouddrive';

    public function getStoreName(): string
    {
        return self::STORE_NAME;
    }

    public function findMountsOfCall(AgentToolCallProvenance $callProvenance): AgentDriveMounts
    {
        $agentDriveMounts = new AgentDriveMounts();
        $agentSpace = $callProvenance->agentSpace;
        if ($agentSpace === null) {
            return $agentDriveMounts;
        }
        $driveSpaceMountResolverService = $this->getDriveSpaceMountResolverService();
        // the agent acts as the audience the host names for the call (the staff by default) for the rest of the job
        // or request: its rights, the type policy and the variant rule of that audience apply to every drive tool
        $this->getDriveSpacesService()->enableAudience($driveSpaceMountResolverService->findAgentAudienceOfCall($callProvenance));
        // an eval case with a scratch drive: that space is the own drive and every further mount is read-only
        $scratchDriveSpace = $callProvenance->workspaceKey !== null
            ? $this->getDriveSpacesService()->findSpaceByScratchKey($callProvenance->workspaceKey)
            : null;
        $ownDriveSpace = $scratchDriveSpace ?? $driveSpaceMountResolverService->findOrCreateOwnDriveSpace($agentSpace);
        if ($ownDriveSpace !== null) {
            $ownDriveMount = $this->buildMount($ownDriveSpace, '', AgentDriveMount::MODE_READ_WRITE, null);
            $ownDriveMount->isOwnDrive = true;
            $agentDriveMounts->add($ownDriveMount);
        }
        foreach ($driveSpaceMountResolverService->findMountsOfAgentSpace($agentSpace)->getElements() as $driveSpaceMount) {
            if ($driveSpaceMount->driveSpace === null || ($ownDriveSpace !== null && $driveSpaceMount->driveSpace->id === $ownDriveSpace->id)) {
                continue;
            }
            $mountMode = $scratchDriveSpace !== null ? AgentDriveMount::MODE_READ_ONLY : $driveSpaceMount->mode;
            // a space the agent's audience may not write (write-locked for it) is mounted read-only
            if ($mountMode !== AgentDriveMount::MODE_READ_ONLY
                && !$this->getDriveSpacesService()->isWriteAllowedForAudience($driveSpaceMount->driveSpace, $this->getDriveSpacesService()->getCurrentAudience())
            ) {
                $mountMode = AgentDriveMount::MODE_READ_ONLY;
            }
            $agentDriveMount = $this->buildMount(
                $driveSpaceMount->driveSpace,
                $driveSpaceMount->mountName,
                $mountMode,
                $driveSpaceMount->audienceDescription
            );
            $agentDriveMounts->add($agentDriveMount);
        }
        return $agentDriveMounts;
    }

    public function listDriveFiles(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, ?string $cursor, int $limit): AgentDriveListing
    {
        $agentDriveMount = $this->requireMount($agentDrivePath);
        $driveSpace = $this->requireSpaceOfMount($agentDriveMount);
        $driveNode = $this->findNodeOfPath($driveSpace, $agentDrivePath);
        if ($driveNode === null) {
            throw new NotFoundException(sprintf('"%s" does not exist (or you may not read it).', $agentDrivePath->argument));
        }
        $agentDriveListing = new AgentDriveListing();
        $agentDriveListing->agentDriveMount = $agentDriveMount;
        $agentDriveListing->agentDriveFiles = new AgentDriveFiles();
        $agentDriveListing->path = $agentDriveMount->getPathPrefix() . $this->getDriveNodesService()->buildPath($driveNode);
        if (!$driveNode->isFolder()) {
            $agentDriveFile = $this->buildAgentDriveFile($agentDriveMount, $driveNode);
            $agentDriveListing->agentDriveFiles->add($agentDriveFile);
            return $agentDriveListing;
        }
        $agentDriveListing->totalCount = (int)$driveNode->childCount;
        $childNodes = $this->getDriveNodesService()->findChildren($driveNode, $cursor, $limit, !$agentDriveMount->isOwnDrive);
        $lastChildNode = null;
        foreach ($childNodes->getElements() as $childNode) {
            $lastChildNode = $childNode;
            $agentDriveFile = $this->buildAgentDriveFile($agentDriveMount, $childNode, rtrim($agentDriveListing->path, '/'));
            $agentDriveListing->agentDriveFiles->add($agentDriveFile);
        }
        if ($lastChildNode !== null && $childNodes->count() >= $limit) {
            $agentDriveListing->nextCursor = DriveNodesService::buildListCursor($lastChildNode);
        }
        return $agentDriveListing;
    }

    public function findDriveFiles(AgentToolCallProvenance $callProvenance, array $agentDrivePaths): AgentDriveFiles
    {
        $agentDriveFiles = new AgentDriveFiles();
        foreach ($agentDrivePaths as $agentDrivePath) {
            try {
                $agentDriveMount = $this->requireMount($agentDrivePath);
                $driveSpace = $this->requireSpaceOfMount($agentDriveMount);
                // a file of the own drive the sandbox just wrote or changed is committed first, so a card shows it
                $this->commitUncommittedSource($callProvenance, $agentDriveMount, $driveSpace, $agentDrivePath);
                $driveNode = $this->findNodeOfPath($driveSpace, $agentDrivePath);
            } catch (NotFoundException|BadRequestException) {
                continue;
            }
            if ($driveNode !== null && !$driveNode->isRoot()) {
                $agentDriveFile = $this->buildAgentDriveFile($agentDriveMount, $driveNode);
                $agentDriveFiles->add($agentDriveFile);
            }
        }
        return $agentDriveFiles;
    }

    public function saveDriveFiles(AgentToolCallProvenance $callProvenance, array $sourcePaths, AgentDriveMount $targetMount, string $targetFolderPath): AgentDriveSaveResult
    {
        $targetSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$targetMount->spaceKey, DBDriveSpace::RIGHT_WRITE);
        $targetFolderNode = $this->getDriveNodesService()->findOrCreateFolderPath($targetSpace, $targetFolderPath);
        $agentDriveSaveResult = new AgentDriveSaveResult();
        $agentDriveSaveResult->savedFiles = new AgentDriveFiles();
        foreach ($sourcePaths as $sourcePath) {
            try {
                $sourceMount = $this->requireMount($sourcePath);
                $sourceSpace = $this->requireSpaceOfMount($sourceMount);
                $commitRefusal = $this->commitUncommittedSource($callProvenance, $sourceMount, $sourceSpace, $sourcePath);
                if ($commitRefusal !== null) {
                    $agentDriveSaveResult->refusedLines[] = $sourcePath->argument . ': ' . $commitRefusal;
                    continue;
                }
                $sourceNode = $this->findNodeOfPath($sourceSpace, $sourcePath);
                if ($sourceNode === null || !$sourceNode->isFile() || $sourceNode->latestVersionId === null) {
                    $agentDriveSaveResult->refusedLines[] = $sourcePath->argument . ': no file of the drive (commit it first, or check the path)';
                    continue;
                }
                $sourceVersion = $this->getDriveVersionsService()->find((int)$sourceNode->latestVersionId, false);
                $sourceBlob = $sourceVersion !== null ? $this->getDriveBlobsService()->find((int)$sourceVersion->driveBlobId, false) : null;
                if (!$sourceBlob instanceof DriveBlob || $sourceBlob->state === DriveBlob::STATE_BLOCKED) {
                    $agentDriveSaveResult->refusedLines[] = $sourcePath->argument . ': its content is blocked or missing';
                    continue;
                }
                $copiedBlob = $this->getDriveBlobsService()->copyBlobIntoSpace($sourceBlob, $targetSpace);
                $driveFileWrite = new DriveFileWrite();
                $driveFileWrite->driveBlobId = (int)$copiedBlob->id;
                $driveFileWrite->comment = 'copied from ' . $sourcePath->getWorkspacePath();
                $driveFileWrite->origin = DriveNode::ORIGIN_COPY;
                $driveFileWrite->isContentUntrusted = (bool)$sourceNode->isContentUntrusted || $sourceSpace->isShared();
                $driveFileWrite->ignoresExpectedVersion = true;
                /** @var DriveChangesService $driveChangesService */
                $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
                $driveFileWrite->authorAccountId = $driveChangesService->findActingAccountIdOfCurrentCaller();
                $driveFileWrite->onBehalfOfAccountId = $driveChangesService->findOnBehalfOfAccountIdOfCurrentCaller();
                $driveFileWrite->copiedFromNodeId = (int)$sourceNode->id;
                $driveFileWrite->copiedFromVersionId = (int)$sourceVersion->id;
                $driveFileWrite->workingCopyKey = $targetMount->isOwnDrive ? $this->findOwnWorkingCopyOfCall($callProvenance, $targetSpace)?->workingCopyKey : null;
                $driveFileWriteResult = $this->getDriveVersionsService()->writeFile($targetSpace, $targetFolderNode, (string)$sourceNode->name, $driveFileWrite);
                if ($driveFileWriteResult->isConflict || $driveFileWriteResult->driveNode === null) {
                    $agentDriveSaveResult->refusedLines[] = $sourcePath->argument . ': ' . ($driveFileWriteResult->conflictReason ?? 'not written');
                    continue;
                }
                if ($driveFileWriteResult->driveFileVersion !== null) {
                    $this->getDriveVersionsService()->ingestVersion($driveFileWriteResult->driveFileVersion, !(bool)$targetFolderNode->isSystem);
                }
                $savedNode = $this->getDriveNodesService()->find((int)$driveFileWriteResult->driveNode->id, false) ?? $driveFileWriteResult->driveNode;
                $agentDriveFile = $this->buildAgentDriveFile($targetMount, $savedNode);
                $agentDriveSaveResult->savedFiles->add($agentDriveFile);
            } catch (NotFoundException|BadRequestException $refusal) {
                $agentDriveSaveResult->refusedLines[] = $sourcePath->argument . ': ' . $refusal->getMessage();
            }
        }
        return $agentDriveSaveResult;
    }

    public function findFileCards(array $references, ?int $widgetSelfHydrationKey): AgentDriveFiles
    {
        $agentDriveFiles = new AgentDriveFiles();
        foreach ($references as $reference) {
            if (preg_match('#^drive/(\d+)$#', (string)$reference, $referenceMatches) !== 1) {
                continue;
            }
            $driveNode = $this->getDriveNodesService()->find((int)$referenceMatches[1], false);
            if (!$driveNode instanceof DriveNode || $driveNode->isTrashed()) {
                continue;
            }
            $agentDriveMount = new AgentDriveMount();
            $agentDriveMount->storeName = self::STORE_NAME;
            $agentDriveMount->spaceKey = (string)$driveNode->driveSpaceId;
            $agentDriveMount->isOwnDrive = true;
            $agentDriveFile = $this->buildAgentDriveFile($agentDriveMount, $driveNode);
            $agentDriveFiles->add($agentDriveFile);
        }
        return $agentDriveFiles;
    }

    /** The node a resolved path or reference names in the space, live only */
    public function findNodeOfPath(DriveSpace $driveSpace, AgentDrivePath $agentDrivePath): ?DriveNode
    {
        if ($agentDrivePath->reference !== null) {
            if (preg_match('#^drive/(\d+)$#', $agentDrivePath->reference, $referenceMatches) !== 1) {
                return null;
            }
            $driveNode = $this->getDriveNodesService()->find((int)$referenceMatches[1], false);
            return $driveNode instanceof DriveNode && !$driveNode->isTrashed() ? $driveNode : null;
        }
        return $this->getDriveNodesService()->findNodeByPath($driveSpace, $agentDrivePath->pathInMount);
    }

    /** One file or folder for the model and the cards: workspace path, facts, state */
    public function buildAgentDriveFile(AgentDriveMount $agentDriveMount, DriveNode $driveNode, ?string $folderPath = null): AgentDriveFile
    {
        $agentDriveFile = new AgentDriveFile();
        $agentDriveFile->storeName = self::STORE_NAME;
        $agentDriveFile->reference = $driveNode->buildReference();
        $agentDriveFile->path = $folderPath !== null
            ? ($folderPath !== '' ? $folderPath . '/' : '') . $driveNode->name
            : $agentDriveMount->getPathPrefix() . $this->getDriveNodesService()->buildPath($driveNode);
        $agentDriveFile->name = (string)$driveNode->name;
        $agentDriveFile->isFolder = $driveNode->isFolder();
        $agentDriveFile->sizeBytes = $driveNode->isFile() ? $driveNode->sizeBytes : null;
        $agentDriveFile->contentType = $driveNode->contentType;
        $agentDriveFile->modifiedAt = $driveNode->getLastEditTime();
        $agentDriveFile->versionNumber = $driveNode->versionNumber;
        $agentDriveFile->childCount = $driveNode->isFolder() ? (int)$driveNode->childCount : null;
        $agentDriveFile->description = $driveNode->description;
        $agentDriveFile->tags = $driveNode->getTagList();
        if ($driveNode->isFile() && $driveNode->latestVersionId !== null && $driveNode->currentVersionId !== $driveNode->latestVersionId) {
            $agentDriveFile->state = 'pending scan';
        } elseif ($driveNode->isContentUntrusted) {
            $agentDriveFile->state = 'written by a third party';
        }
        $this->applyOpenLink($agentDriveFile, $driveNode);
        return $agentDriveFile;
    }

    /**
     * The card's link into the host's drive screen (`CloudDrive.settings.adminLinkTemplate`), when the template is set
     * and the installed ddd-agents knows the field (`AgentDriveFile::$openLink` exists since 0.1.33).
     */
    protected function applyOpenLink(AgentDriveFile $agentDriveFile, DriveNode $driveNode): void
    {
        if (!property_exists($agentDriveFile, 'openLink') || !method_exists($agentDriveFile, 'setOpenLinkIfSafe')) {
            return;
        }
        $driveNodesService = $this->getDriveNodesService();
        if ($driveNodesService->getAdminLinkTemplate() === null) {
            return;
        }
        $agentDriveFile->setOpenLinkIfSafe($driveNodesService->buildAdminLink($driveNode));
    }

    /**
     * The working copy of the call's sandbox on the own drive space: by the resolver's key (created on first use), else
     * the space's newest working copy; null without one.
     */
    public function findOwnWorkingCopyOfCall(AgentToolCallProvenance $callProvenance, DriveSpace $ownDriveSpace): ?DriveWorkingCopy
    {
        $driveWorkingCopiesService = $this->getDriveWorkingCopiesService();
        $workingCopyKey = $this->getDriveSpaceMountResolverService()->findWorkingCopyKeyOfCall($callProvenance);
        return $workingCopyKey !== null
            ? $driveWorkingCopiesService->findOrCreateWorkingCopy($ownDriveSpace, $workingCopyKey)
            : $driveWorkingCopiesService->findNewestWorkingCopy($ownDriveSpace);
    }

    /**
     * A source of the own drive with a saved, uncommitted change is committed first, so the copy carries what the
     * sandbox holds now. Answers why the source cannot be copied (the commit failed or left a conflict), null when it can.
     */
    protected function commitUncommittedSource(AgentToolCallProvenance $callProvenance, AgentDriveMount $sourceMount, DriveSpace $sourceSpace, AgentDrivePath $sourcePath): ?string
    {
        if (!$sourceMount->isOwnDrive || $sourcePath->reference !== null) {
            return null;
        }
        try {
            $driveWorkingCopiesService = $this->getDriveWorkingCopiesService();
            $driveWorkingCopy = $this->findOwnWorkingCopyOfCall($callProvenance, $sourceSpace);
            if ($driveWorkingCopy === null) {
                return null;
            }
            // the host flushes its live sandbox first: a change not yet saved becomes an entry
            $driveWorkingCopiesService->findCurrentEntries($driveWorkingCopy);
            if ($driveWorkingCopiesService->findEntryByPath($driveWorkingCopy, $sourcePath->pathInMount) === null) {
                return null;
            }
            $driveCommitResult = $driveWorkingCopiesService->commitChanges($driveWorkingCopy, 'committed before a copy', [$sourcePath->pathInMount]);
            if (($driveCommitResult->conflictedFiles?->count() ?? 0) > 0 || $driveCommitResult->refusedLines !== []) {
                return 'its saved change could not be committed first (' . str_replace("\n", '; ', $driveCommitResult->buildSummaryLine()) . '): resolve it with commitDriveChanges, then copy';
            }
            return null;
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[CloudDrive] commit before a copy failed', ['path' => $sourcePath->pathInMount, 'reason' => $throwable->getMessage()]);
            return 'its saved change could not be committed first: ' . $throwable->getMessage();
        }
    }

    protected function buildMount(DriveSpace $driveSpace, string $mountName, string $mode, ?string $audienceDescription): AgentDriveMount
    {
        $agentDriveMount = new AgentDriveMount();
        $agentDriveMount->storeName = self::STORE_NAME;
        $agentDriveMount->mountName = $mountName;
        $agentDriveMount->spaceKey = (string)$driveSpace->id;
        $agentDriveMount->title = $driveSpace->buildDisplayTitle();
        $agentDriveMount->mode = $driveSpace->isShared() && $mode === AgentDriveMount::MODE_READ_WRITE ? AgentDriveMount::MODE_WRITE_WITH_CONSENT : $mode;
        $agentDriveMount->isOutwardVisible = $driveSpace->isShared();
        $agentDriveMount->audienceDescription = $audienceDescription;
        $agentDriveMount->fileCount = (int)$driveSpace->fileCount;
        return $agentDriveMount;
    }

    /**
     * @throws BadRequestException
     */
    protected function requireMount(AgentDrivePath $agentDrivePath): AgentDriveMount
    {
        if ($agentDrivePath->agentDriveMount === null || $agentDrivePath->agentDriveMount->storeName !== self::STORE_NAME) {
            throw new BadRequestException(sprintf('"%s" is no path of the drive.', $agentDrivePath->argument));
        }
        return $agentDrivePath->agentDriveMount;
    }

    /**
     * @throws NotFoundException
     */
    protected function requireSpaceOfMount(AgentDriveMount $agentDriveMount): DriveSpace
    {
        return $this->getDriveSpacesService()->requireSpaceWithRight((int)$agentDriveMount->spaceKey, DBDriveSpace::RIGHT_READ);
    }

    protected function getDriveSpaceMountResolverService(): DriveSpaceMountResolverService
    {
        /** @var DriveSpaceMountResolverService $driveSpaceMountResolverService */
        $driveSpaceMountResolverService = DDDService::instance()->getService(DriveSpaceMountResolverService::class);
        return $driveSpaceMountResolverService;
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

    protected function getDriveVersionsService(): DriveVersionsService
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        return $driveVersionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }

    protected function getDriveWorkingCopiesService(): DriveWorkingCopiesService
    {
        /** @var DriveWorkingCopiesService $driveWorkingCopiesService */
        $driveWorkingCopiesService = DDDService::instance()->getService(DriveWorkingCopiesService::class);
        return $driveWorkingCopiesService;
    }
}
