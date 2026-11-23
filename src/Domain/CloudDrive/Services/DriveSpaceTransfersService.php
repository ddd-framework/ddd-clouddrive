<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkSkip;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeCounts;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveSpaceTransferPreview;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveSpaceTransferResult;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferLostLinks;
use DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWrite;
use DDD\Domain\CloudDrive\MessageHandlers\DriveSpaceTransferMessage;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * Moves files and folders to another space: a preview first (quota of the target, taken names, what the target's type
 * policy refuses, links of the application that are lost), then the move — inside the store a copy of each file's
 * current content (content the target holds already is shared, no byte passes PHP), the file written in the target
 * (its ingest scans it under the target's rules), the application told ({@see DriveSpaceTransferListener}), then the
 * moved source nodes trashed and purged. A folder moves with its live content; a file the target refuses stays in the
 * source (and so does its folder). The history of versions stays behind: the target starts with the current version.
 * Needs the write right on both spaces. As a job with `CloudDrive.settings.asyncTransfer` ({@see DriveSpaceTransferMessage}).
 */
class DriveSpaceTransfersService extends Service
{
    /** @var int The most files one transfer moves (subtrees included) */
    public const int MAX_TRANSFERRED_FILES = 2000;

    /** @var iterable<DriveSpaceTransferListener> */
    protected iterable $listeners = [];

    /** @param iterable<DriveSpaceTransferListener> $listeners */
    #[Required]
    public function setListeners(
        #[AutowireIterator(DriveSpaceTransferListener::TAG)]
        iterable $listeners
    ): void {
        $this->listeners = $listeners;
    }

    /**
     * What moving the nodes (all of one space) into the target would do; nothing is written.
     *
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function previewTransfer(DriveNodes $driveNodes, DriveSpace $targetDriveSpace, ?DriveNode $targetFolderNode): DriveSpaceTransferPreview
    {
        $sourceDriveSpace = $this->requireTransferSpaces($driveNodes, $targetDriveSpace);
        $targetFolderNode = $this->requireTargetFolder($targetDriveSpace, $targetFolderNode);
        $driveTypePolicy = $this->getDriveTypePoliciesService()->buildTypePolicyForSpace($targetDriveSpace, $this->getDriveSpacesService()->getCurrentAudience());
        $driveSpaceTransferPreview = new DriveSpaceTransferPreview();
        $driveSpaceTransferPreview->sourceDriveSpaceId = (int)$sourceDriveSpace->id;
        $driveSpaceTransferPreview->targetDriveSpaceId = (int)$targetDriveSpace->id;
        $subtreeNodes = new DriveNodes();
        foreach ($driveNodes->getElements() as $driveNode) {
            if ($this->getDriveNodesService()->findChildByName($targetFolderNode, (string)$driveNode->name) !== null) {
                $nameConflictSkip = new DriveNodeBulkSkip();
                $nameConflictSkip->driveNodeId = (int)$driveNode->id;
                $nameConflictSkip->reason = sprintf('"%s" exists in the target folder: it moves as "%s"', $driveNode->name, $this->getDriveNodesService()->findFreeName($targetFolderNode, (string)$driveNode->name));
                $driveSpaceTransferPreview->nameConflicts->add($nameConflictSkip);
            }
            $this->collectSubtree($driveNode, $subtreeNodes);
        }
        foreach ($subtreeNodes->getElements() as $subtreeNode) {
            if ($subtreeNode->isFolder()) {
                $driveSpaceTransferPreview->folderCount++;
                continue;
            }
            $refusalReason = $this->findFileRefusalReason($subtreeNode, $driveTypePolicy);
            if ($refusalReason !== null) {
                $refusedSkip = new DriveNodeBulkSkip();
                $refusedSkip->driveNodeId = (int)$subtreeNode->id;
                $refusedSkip->reason = $refusalReason;
                $driveSpaceTransferPreview->refused->add($refusedSkip);
                continue;
            }
            $driveSpaceTransferPreview->fileCount++;
            $driveSpaceTransferPreview->totalBytes += (int)$subtreeNode->sizeBytes;
        }
        $driveSpaceTransferPreview->targetFreeBytes = max(0, $targetDriveSpace->getEffectiveQuotaBytes() - (int)$targetDriveSpace->usedBytes);
        $driveSpaceTransferPreview->fitsTargetQuota = $driveSpaceTransferPreview->totalBytes <= $driveSpaceTransferPreview->targetFreeBytes;
        $driveSpaceTransferPreview->lostLinks = $this->findLinksLostOnTransfer($subtreeNodes, $targetDriveSpace);
        $driveSpaceTransferPreview->isTransferable = $driveSpaceTransferPreview->fitsTargetQuota
            && ($driveSpaceTransferPreview->fileCount > 0 || $driveSpaceTransferPreview->folderCount > 0)
            && $driveSpaceTransferPreview->fileCount <= static::MAX_TRANSFERRED_FILES;
        return $driveSpaceTransferPreview;
    }

    /**
     * Moves the nodes (all of one space) into a folder of the target space (its root when none is given): as a job
     * when `$async` (default: `CloudDrive.settings.asyncTransfer`) — the answer is `isQueued` then — else right here.
     *
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function transferNodes(DriveNodes $driveNodes, DriveSpace $targetDriveSpace, ?DriveNode $targetFolderNode, ?bool $async = null): DriveSpaceTransferResult
    {
        $sourceDriveSpace = $this->requireTransferSpaces($driveNodes, $targetDriveSpace);
        $targetFolderNode = $this->requireTargetFolder($targetDriveSpace, $targetFolderNode);
        if ($async ?? (bool)Config::get('CloudDrive.settings.asyncTransfer')) {
            $driveNodeIds = [];
            foreach ($driveNodes->getElements() as $driveNode) {
                $driveNodeIds[] = (int)$driveNode->id;
            }
            (new DriveSpaceTransferMessage(
                (int)$sourceDriveSpace->id,
                $driveNodeIds,
                (int)$targetDriveSpace->id,
                (int)$targetFolderNode->id,
                $this->getDriveSpacesService()->getCurrentAudience()
            ))->dispatch();
            $queuedResult = new DriveSpaceTransferResult();
            $queuedResult->isQueued = true;
            return $queuedResult;
        }
        return $this->runTransfer($sourceDriveSpace, $driveNodes, $targetDriveSpace, $targetFolderNode);
    }

    /**
     * The job of {@see DriveSpaceTransferMessage}: loads spaces and nodes under the asking account's rights and moves.
     *
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws InternalErrorException
     */
    public function runTransferMessage(DriveSpaceTransferMessage $driveSpaceTransferMessage): DriveSpaceTransferResult
    {
        $driveSpacesService = $this->getDriveSpacesService();
        $previousAudience = $driveSpacesService->enableAudience($driveSpaceTransferMessage->audience);
        try {
            $sourceDriveSpace = $driveSpacesService->requireSpaceWithRight((int)$driveSpaceTransferMessage->sourceDriveSpaceId, DBDriveSpace::RIGHT_WRITE);
            $targetDriveSpace = $driveSpacesService->requireSpaceWithRight((int)$driveSpaceTransferMessage->targetDriveSpaceId, DBDriveSpace::RIGHT_WRITE);
            $driveNodes = new DriveNodes();
            foreach ($driveSpaceTransferMessage->driveNodeIds as $driveNodeId) {
                $driveNode = $this->getDriveNodesService()->findNodeOfSpace($sourceDriveSpace, (int)$driveNodeId);
                if ($driveNode !== null && !$driveNode->isTrashed()) {
                    $driveNodes->add($driveNode);
                }
            }
            $targetFolderNode = $driveSpaceTransferMessage->targetFolderNodeId !== null
                ? $this->getDriveNodesService()->findNodeOfSpace($targetDriveSpace, (int)$driveSpaceTransferMessage->targetFolderNodeId)
                : null;
            if ($driveNodes->count() === 0) {
                return new DriveSpaceTransferResult();
            }
            return $this->transferNodes($driveNodes, $targetDriveSpace, $targetFolderNode, false);
        } finally {
            $driveSpacesService->restoreAudience($previousAudience);
        }
    }

    /**
     * The move itself (see the class).
     *
     * @throws InternalErrorException
     */
    protected function runTransfer(DriveSpace $sourceDriveSpace, DriveNodes $driveNodes, DriveSpace $targetDriveSpace, DriveNode $targetFolderNode): DriveSpaceTransferResult
    {
        $driveTypePolicy = $this->getDriveTypePoliciesService()->buildTypePolicyForSpace($targetDriveSpace, $this->getDriveSpacesService()->getCurrentAudience());
        $driveSpaceTransferResult = new DriveSpaceTransferResult();
        $movedSourceNodes = new DriveNodes();
        $fullyMovedRootNodes = new DriveNodes();
        $partlyMovedFileNodes = new DriveNodes();
        foreach ($driveNodes->getElements() as $driveNode) {
            $subtreeMovedNodes = new DriveNodes();
            $isFullyMoved = $this->transferNode($driveNode, $targetDriveSpace, $targetFolderNode, $driveTypePolicy, $driveSpaceTransferResult, $subtreeMovedNodes);
            foreach ($subtreeMovedNodes->getElements() as $subtreeMovedNode) {
                $movedSourceNodes->add($subtreeMovedNode);
            }
            if ($isFullyMoved) {
                $fullyMovedRootNodes->add($driveNode);
                $driveSpaceTransferResult->processedCount++;
                continue;
            }
            foreach ($subtreeMovedNodes->getElements() as $subtreeMovedNode) {
                if ($subtreeMovedNode->isFile()) {
                    $partlyMovedFileNodes->add($subtreeMovedNode);
                }
            }
        }
        if ($movedSourceNodes->count() === 0) {
            return $driveSpaceTransferResult;
        }
        $this->notifyNodesTransferred($movedSourceNodes, $sourceDriveSpace, $targetDriveSpace, $driveSpaceTransferResult);
        $this->purgeMovedSourceNodes($sourceDriveSpace, $fullyMovedRootNodes, $partlyMovedFileNodes);
        return $driveSpaceTransferResult;
    }

    /**
     * Moves one node (a folder with its live content); true when everything of it moved.
     */
    protected function transferNode(
        DriveNode $driveNode,
        DriveSpace $targetDriveSpace,
        DriveNode $targetFolderNode,
        DriveTypePolicy $driveTypePolicy,
        DriveSpaceTransferResult $driveSpaceTransferResult,
        DriveNodes $movedSourceNodes
    ): bool {
        $targetName = $this->getDriveNodesService()->findFreeName($targetFolderNode, (string)$driveNode->name);
        if ($driveNode->isFolder()) {
            try {
                $targetSubfolderNode = $this->getDriveNodesService()->createFolder($targetDriveSpace, $targetFolderNode, $targetName);
            } catch (Throwable $throwable) {
                $driveSpaceTransferResult->addSkip((int)$driveNode->id, 'the folder could not be created in the target: ' . $throwable->getMessage());
                return false;
            }
            $driveSpaceTransferResult->folderCount++;
            $driveSpaceTransferResult->addTransferredNode((int)$driveNode->id, (int)$targetSubfolderNode->id);
            $movedSourceNodes->add($driveNode);
            $isFullyMoved = true;
            $childCursor = null;
            do {
                $childNodes = $this->getDriveNodesService()->findChildren($driveNode, $childCursor, DriveNodesService::MAX_LIST_LIMIT);
                $lastChildNode = null;
                foreach ($childNodes->getElements() as $childNode) {
                    $lastChildNode = $childNode;
                    $isFullyMoved = $this->transferNode($childNode, $targetDriveSpace, $targetSubfolderNode, $driveTypePolicy, $driveSpaceTransferResult, $movedSourceNodes) && $isFullyMoved;
                }
                $childCursor = $lastChildNode !== null && $childNodes->count() >= DriveNodesService::MAX_LIST_LIMIT ? DriveNodesService::buildListCursor($lastChildNode) : null;
            } while ($childCursor !== null);
            return $isFullyMoved;
        }
        if ($driveSpaceTransferResult->fileCount >= static::MAX_TRANSFERRED_FILES) {
            $driveSpaceTransferResult->addSkip((int)$driveNode->id, sprintf('more than %d files in one transfer', static::MAX_TRANSFERRED_FILES));
            return false;
        }
        $refusalReason = $this->findFileRefusalReason($driveNode, $driveTypePolicy);
        if ($refusalReason !== null) {
            $driveSpaceTransferResult->addSkip((int)$driveNode->id, $refusalReason);
            return false;
        }
        try {
            $sourceVersion = $this->getDriveVersionsService()->find((int)$driveNode->currentVersionId, false);
            $sourceBlob = $sourceVersion instanceof DriveFileVersion ? $this->getDriveBlobsService()->find((int)$sourceVersion->driveBlobId, false) : null;
            if (!$sourceVersion instanceof DriveFileVersion || !$sourceBlob instanceof DriveBlob) {
                $driveSpaceTransferResult->addSkip((int)$driveNode->id, 'its content is missing');
                return false;
            }
            $copiedBlob = $this->getDriveBlobsService()->copyBlobIntoSpace($sourceBlob, $targetDriveSpace);
            $driveFileWrite = new DriveFileWrite();
            $driveFileWrite->driveBlobId = (int)$copiedBlob->id;
            $driveFileWrite->comment = sprintf('moved from space %d', (int)$driveNode->driveSpaceId);
            $driveFileWrite->origin = DriveNode::ORIGIN_COPY;
            $driveFileWrite->isContentUntrusted = (bool)$driveNode->isContentUntrusted;
            $driveFileWrite->ignoresExpectedVersion = true;
            $authAccount = AuthService::instance()->getAccount();
            $driveFileWrite->authorAccountId = $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
            $driveFileWrite->copiedFromNodeId = (int)$driveNode->id;
            $driveFileWrite->copiedFromVersionId = (int)$sourceVersion->id;
            $driveFileWriteResult = $this->getDriveVersionsService()->writeFile($targetDriveSpace, $targetFolderNode, $targetName, $driveFileWrite);
            if ($driveFileWriteResult->isConflict || $driveFileWriteResult->driveNode === null) {
                $driveSpaceTransferResult->addSkip((int)$driveNode->id, (string)($driveFileWriteResult->conflictReason ?? 'not written in the target'));
                return false;
            }
            if ($driveFileWriteResult->driveFileVersion !== null) {
                $this->getDriveVersionsService()->ingestVersion($driveFileWriteResult->driveFileVersion, !(bool)$targetFolderNode->isSystem);
            }
        } catch (Throwable $throwable) {
            $driveSpaceTransferResult->addSkip((int)$driveNode->id, 'not moved: ' . mb_substr($throwable->getMessage(), 0, 300));
            return false;
        }
        $driveSpaceTransferResult->fileCount++;
        $driveSpaceTransferResult->addTransferredNode((int)$driveNode->id, (int)$driveFileWriteResult->driveNode->id);
        $movedSourceNodes->add($driveNode);
        return true;
    }

    /** Why the target does not take a file: its type policy, content that is not released; null when it does */
    protected function findFileRefusalReason(DriveNode $driveNode, DriveTypePolicy $driveTypePolicy): ?string
    {
        $fileNameRefusalReason = $driveTypePolicy->findFileNameRefusalReason((string)$driveNode->name);
        if ($fileNameRefusalReason !== null) {
            return $fileNameRefusalReason;
        }
        $sizeRefusalReason = $driveTypePolicy->findSizeRefusalReason((int)$driveNode->sizeBytes);
        if ($sizeRefusalReason !== null) {
            return $sizeRefusalReason;
        }
        if ($driveNode->currentVersionId === null) {
            return 'it has no released version (it waits for its scan, or the scan blocked it)';
        }
        return null;
    }

    /** The nodes and every live node below them */
    protected function collectSubtree(DriveNode $driveNode, DriveNodes $subtreeNodes): void
    {
        $subtreeNodes->add($driveNode);
        if (!$driveNode->isFolder() || $subtreeNodes->count() > static::MAX_TRANSFERRED_FILES * 2) {
            return;
        }
        $childCursor = null;
        do {
            $childNodes = $this->getDriveNodesService()->findChildren($driveNode, $childCursor, DriveNodesService::MAX_LIST_LIMIT);
            $lastChildNode = null;
            foreach ($childNodes->getElements() as $childNode) {
                $lastChildNode = $childNode;
                $this->collectSubtree($childNode, $subtreeNodes);
            }
            $childCursor = $lastChildNode !== null && $childNodes->count() >= DriveNodesService::MAX_LIST_LIMIT ? DriveNodesService::buildListCursor($lastChildNode) : null;
        } while ($childCursor !== null);
    }

    protected function findLinksLostOnTransfer(DriveNodes $driveNodes, DriveSpace $targetDriveSpace): DriveTransferLostLinks
    {
        $driveTransferLostLinks = new DriveTransferLostLinks();
        foreach ($this->listeners as $driveSpaceTransferListener) {
            try {
                foreach ($driveSpaceTransferListener->findLinksLostOnTransfer($driveNodes, $targetDriveSpace)->getElements() as $driveTransferLostLink) {
                    $driveTransferLostLinks->add($driveTransferLostLink);
                }
            } catch (Throwable $throwable) {
                $this->logListenerFailure($driveSpaceTransferListener, 'findLinksLostOnTransfer', $throwable);
            }
        }
        return $driveTransferLostLinks;
    }

    protected function notifyNodesTransferred(DriveNodes $movedSourceNodes, DriveSpace $sourceDriveSpace, DriveSpace $targetDriveSpace, DriveSpaceTransferResult $driveSpaceTransferResult): void
    {
        foreach ($this->listeners as $driveSpaceTransferListener) {
            try {
                $driveSpaceTransferListener->onNodesTransferred($movedSourceNodes, $sourceDriveSpace, $targetDriveSpace, $driveSpaceTransferResult->transferredNodes);
            } catch (Throwable $throwable) {
                $this->logListenerFailure($driveSpaceTransferListener, 'onNodesTransferred', $throwable);
            }
        }
    }

    /**
     * Trashes and purges what moved: whole roots that moved completely, single files of roots that moved only in part
     * (their folders stay with what did not move). The purge tells the lifecycle listeners.
     */
    protected function purgeMovedSourceNodes(DriveSpace $sourceDriveSpace, DriveNodes $fullyMovedRootNodes, DriveNodes $partlyMovedFileNodes): void
    {
        $nodesToPurge = new DriveNodes();
        foreach ([...$fullyMovedRootNodes->getElements(), ...$partlyMovedFileNodes->getElements()] as $movedNode) {
            $trashedNode = $this->getDriveNodesService()->trashNode($movedNode);
            $nodesToPurge->add($trashedNode);
        }
        /** @var DriveMaintenanceService $driveMaintenanceService */
        $driveMaintenanceService = DDDService::instance()->getService(DriveMaintenanceService::class);
        $purgedNodeIds = [];
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach ($nodesToPurge->getElements() as $nodeToPurge) {
                $purgedCounts = new DriveNodeCounts();
                $driveMaintenanceService->purgeNode($nodeToPurge, $purgedCounts);
                array_push($purgedNodeIds, ...$purgedCounts->driveNodeIds);
            }
            $driveMaintenanceService->sweepBlobs(false, (int)$sourceDriveSpace->id);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        /** @var DriveNodeLifecycleListenersService $driveNodeLifecycleListenersService */
        $driveNodeLifecycleListenersService = DDDService::instance()->getService(DriveNodeLifecycleListenersService::class);
        $driveNodeLifecycleListenersService->notifyNodesPurged($sourceDriveSpace, $purgedNodeIds);
    }

    /**
     * The source space of the nodes (one for all), with the write right on it and on the target.
     *
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    protected function requireTransferSpaces(DriveNodes $driveNodes, DriveSpace $targetDriveSpace): DriveSpace
    {
        $sourceDriveSpaceIds = [];
        foreach ($driveNodes->getElements() as $driveNode) {
            if ($driveNode->isRoot() || $driveNode->isTrashed()) {
                throw new BadRequestException(sprintf('Node %d cannot move (the root, or in the trash).', (int)$driveNode->id));
            }
            $sourceDriveSpaceIds[(int)$driveNode->driveSpaceId] = true;
        }
        if (count($sourceDriveSpaceIds) !== 1) {
            throw new BadRequestException('A transfer moves nodes of exactly one space.');
        }
        $sourceDriveSpaceId = (int)array_key_first($sourceDriveSpaceIds);
        if ($sourceDriveSpaceId === (int)$targetDriveSpace->id) {
            throw new BadRequestException('The target is the same space: move inside it instead.');
        }
        $sourceDriveSpace = $this->getDriveSpacesService()->requireSpaceWithRight($sourceDriveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$targetDriveSpace->id, DBDriveSpace::RIGHT_WRITE);
        return $sourceDriveSpace;
    }

    /**
     * @throws NotFoundException
     */
    protected function requireTargetFolder(DriveSpace $targetDriveSpace, ?DriveNode $targetFolderNode): DriveNode
    {
        $targetFolderNode ??= $this->getDriveNodesService()->findRootNode($targetDriveSpace);
        if ($targetFolderNode === null || !$targetFolderNode->isFolder() || $targetFolderNode->isTrashed() || (int)$targetFolderNode->driveSpaceId !== (int)$targetDriveSpace->id) {
            throw new NotFoundException('Target folder not found in the target space.');
        }
        return $targetFolderNode;
    }

    protected function logListenerFailure(DriveSpaceTransferListener $driveSpaceTransferListener, string $hookName, Throwable $throwable): void
    {
        DDDService::instance()->getLogger()->warning('[CloudDrive] space transfer listener failed', [
            'listener' => $driveSpaceTransferListener::class,
            'hook' => $hookName,
            'reason' => $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 500),
        ]);
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

    protected function getDriveTypePoliciesService(): DriveTypePoliciesService
    {
        /** @var DriveTypePoliciesService $driveTypePoliciesService */
        $driveTypePoliciesService = DDDService::instance()->getService(DriveTypePoliciesService::class);
        return $driveTypePoliciesService;
    }
}
