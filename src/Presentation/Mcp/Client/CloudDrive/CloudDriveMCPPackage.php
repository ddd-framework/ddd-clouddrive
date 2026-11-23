<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerConfirmation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Drives\AgentDriveStoresService;
use DDD\Domain\CloudDrive\Entities\AI\Conversations\Widgets\DriveLocationConversationWidget;
use DDD\Domain\CloudDrive\Entities\AI\Mounts\DriveMountedNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolution;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolutions;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\Agents\CloudDriveAgentDriveStoreService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveChangesCommitRequestDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveFileGetRequestDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveFilesFindRequestDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveFilesOrganizeRequestDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveFilesRestoreRequestDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveFilesTrashRequestDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveLocationShowRequestDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveMcpResponseDto;
use DDD\Presentation\Mcp\Client\CloudDrive\Dtos\DriveStatusRequestDto;

/**
 * The cloud drive's own agent tools: the working copy of the own drive (status, commit with conflict decisions), and
 * finding, reading facts of, organising, trashing and restoring files in the mounted spaces. In the own drive the
 * agent uses the shell (mv, mkdir, rm) and commits; browsing and copying are the `agent.drive` tools of ddd-agents.
 */
class CloudDriveMCPPackage extends MCPPackage
{
    public static ?string $name = 'clouddrive';

    public static ?string $description = 'The cloud drive: driveStatus and commitDriveChanges for the own drive (the workspace), findDriveFiles and getDriveFile without a download, organizeDriveFiles, trashDriveFiles and restoreDriveFiles in mounted spaces, showDriveLocation as a link.';

    public const int DEFAULT_FIND_LIMIT = 30;
    public const int MAX_FIND_LIMIT = 100;
    public const int MAX_TRASH_PATHS = 50;
    public const string OWN_SPACE_FILTER = 'own';

    /**
     * The state of the own drive and the mounts: uncommitted changes (with conflicts and their reason), every mount
     * with its write mode. Read it before a commit and when a commit reported a conflict.
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads of the drive tables')]
    #[MCPToolSummary('The own drive\'s uncommitted changes and conflicts, and every mount with its write mode.')]
    public function driveStatus(
        DriveStatusRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveWorkingCopiesService $driveWorkingCopiesService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall($callProvenance);
        $statusLines = $agentDriveMounts->count() > 0 ? explode("\n", $agentDriveMounts->buildStateLines()) : ['no drive mounted'];
        $driveWorkingCopy = $this->findOwnWorkingCopy($agentDriveMounts, $callProvenance);
        if ($driveWorkingCopy === null) {
            return DriveMcpResponseDto::withLines($statusLines, 'No working copy of the own drive in this conversation.');
        }
        $changeLines = [];
        foreach ($driveWorkingCopiesService->findEntries($driveWorkingCopy)->getElements() as $driveWorkingCopyEntry) {
            if (!$requestDto->all && str_starts_with((string)$driveWorkingCopyEntry->path, 'work/')) {
                continue;
            }
            $changeLines[] = '- ' . $driveWorkingCopyEntry->path . ' · ' . strtolower((string)$driveWorkingCopyEntry->changeKind)
                . ($driveWorkingCopyEntry->hasConflict() ? ' · CONFLICT: ' . $driveWorkingCopyEntry->conflictReason . ' (commitDriveChanges with resolve)' : ' · uncommitted');
        }
        return DriveMcpResponseDto::withLines([...$statusLines, ...($changeLines === [] ? ['nothing uncommitted'] : $changeLines)]);
    }

    /**
     * Commits the saved changes of the own drive as versions with the message as comment (the turn end commits what is
     * left automatically). A file someone changed meanwhile is a conflict and stays uncommitted: decide with
     * `resolve` — `path=mine`, `path=theirs` or `path=both:<new name>`.
     *
     * @example {"message": "Zuordnung der Gewinncodes"}
     * @example {"message": "Plan überarbeitet", "paths": ["reports/Plan.md"], "resolve": ["reports/Plan.md=mine"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WORKSPACE)]
    #[MCPToolSummary('Commits the own drive\'s saved changes as versions with a message; conflicts stay uncommitted until resolved (mine, theirs, both).')]
    public function commitDriveChanges(
        DriveChangesCommitRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveWorkingCopiesService $driveWorkingCopiesService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $message = trim($requestDto->message);
        if ($message === '') {
            throw new BadRequestException('commitDriveChanges needs a `message`: one line saying what changed.');
        }
        $driveWorkingCopy = $this->findOwnWorkingCopy($agentDriveStoresService->findMountsOfCall($callProvenance), $callProvenance);
        if ($driveWorkingCopy === null) {
            throw new BadRequestException('This conversation has no own drive to commit to.');
        }
        $driveCommitResult = $driveWorkingCopiesService->commitChanges(
            $driveWorkingCopy,
            mb_substr($message, 0, 2000),
            $requestDto->paths !== [] ? array_values(array_map('strval', $requestDto->paths)) : null,
            $this->parseResolutions($requestDto->resolve),
            $requestDto->paths === [] && isset($callProvenance->conversation?->id) ? (string)$callProvenance->conversation->id : null
        );
        return DriveMcpResponseDto::withLines(explode("\n", $driveCommitResult->buildSummaryLine()));
    }

    /**
     * Finds files and folders by name (case and accents do not matter) or by `#tag` in the own drive and the mounts,
     * without a download. Answers paths with size, date, version and reference.
     *
     * @example {"query": "Gewinncodes"}
     * @example {"query": "#logo", "space": "library"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads of the drive tables')]
    #[MCPToolSummary('Finds drive files by name or #tag in the own drive and the mounts, without a download.')]
    public function findDriveFiles(
        DriveFilesFindRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveNodesService $driveNodesService,
        CloudDriveAgentDriveStoreService $cloudDriveAgentDriveStoreService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $query = trim($requestDto->query);
        if ($query === '') {
            throw new BadRequestException('findDriveFiles needs a `query`.');
        }
        $limit = max(1, min(self::MAX_FIND_LIMIT, $requestDto->limit ?? self::DEFAULT_FIND_LIMIT));
        $resultLines = [];
        foreach ($this->findCloudDriveMounts($agentDriveStoresService->findMountsOfCall($callProvenance), $requestDto->space)->getElements() as $agentDriveMount) {
            $foundNodes = str_starts_with($query, '#')
                ? $this->findNodesByTag((int)$agentDriveMount->spaceKey, substr($query, 1), $limit)
                : $driveNodesService->searchNodesByName([(int)$agentDriveMount->spaceKey], $query, $limit);
            foreach ($foundNodes->getElements() as $foundNode) {
                $resultLines[] = '- ' . $cloudDriveAgentDriveStoreService->buildAgentDriveFile($agentDriveMount, $foundNode)->buildLlmLine();
                if (count($resultLines) >= $limit) {
                    break 2;
                }
            }
        }
        return DriveMcpResponseDto::withLines($resultLines === [] ? ['(nothing found)'] : $resultLines);
    }

    /**
     * The facts of one file without a download: path, size, type, tags, description, origin and its versions
     * (number, date, comment, state).
     *
     * @example {"path": "reports/Plan.md"}
     * @example {"path": "drive/812"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads of the drive tables')]
    #[MCPToolSummary('The facts and the versions of one drive file, without a download.')]
    public function getDriveFile(
        DriveFileGetRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveVersionsService $driveVersionsService,
        CloudDriveAgentDriveStoreService $cloudDriveAgentDriveStoreService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $driveMountedNode = $this->requireNodeOfArgument($agentDriveStoresService, $cloudDriveAgentDriveStoreService, $callProvenance, $requestDto->path);
        $agentDriveMount = $driveMountedNode->agentDriveMount;
        $driveNode = $driveMountedNode->driveNode;
        $fileLines = ['- ' . $cloudDriveAgentDriveStoreService->buildAgentDriveFile($agentDriveMount, $driveNode)->buildLlmLine()];
        if ($driveNode->copiedFromNodeId !== null) {
            $fileLines[] = 'copied from drive/' . $driveNode->copiedFromNodeId . ' (no sync afterwards)';
        }
        if ($driveNode->isFile()) {
            foreach ($driveVersionsService->findVersionsOfNode($driveNode, 20)->getElements() as $driveFileVersion) {
                $fileLines[] = sprintf(
                    '  v%d · %s · %s%s',
                    (int)$driveFileVersion->versionNumber,
                    $driveFileVersion->getCreatedTime()?->format('Y-m-d H:i') ?? '',
                    $driveFileVersion->id === $driveNode->currentVersionId ? 'current' : ((int)$driveFileVersion->id > (int)$driveNode->currentVersionId ? 'pending scan' : 'older'),
                    $driveFileVersion->comment !== null ? ' · ' . $driveFileVersion->comment : ''
                );
            }
        }
        return DriveMcpResponseDto::withLines($fileLines);
    }

    /**
     * Moves, renames, tags or describes one file or folder of a mounted space. A read-only mount refuses; a space
     * people outside the staff see asks the owner first. In the own drive use mv in the shell and commit.
     *
     * @example {"path": "drivespaces/library/Brand/Logo alt.svg", "moveTo": "drivespaces/library/Brand/Archiv"}
     * @example {"path": "drive/812", "tags": ["logo", "2026"], "description": "Logo der Sommeraktion"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolRequiresOwnerConfirmation]
    #[MCPToolSummary('Moves, renames, tags or describes one file or folder of a mounted drive space.')]
    public function organizeDriveFiles(
        DriveFilesOrganizeRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveNodesService $driveNodesService,
        OwnerConsentsService $ownerConsentsService,
        CloudDriveAgentDriveStoreService $cloudDriveAgentDriveStoreService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $driveMountedNode = $this->requireNodeOfArgument($agentDriveStoresService, $cloudDriveAgentDriveStoreService, $callProvenance, $requestDto->path);
        $agentDriveMount = $driveMountedNode->agentDriveMount;
        $driveNode = $driveMountedNode->driveNode;
        $targetFolderNode = null;
        if ($requestDto->moveTo !== null && trim($requestDto->moveTo) !== '') {
            $driveMountedNode = $this->requireNodeOfArgument($agentDriveStoresService, $cloudDriveAgentDriveStoreService, $callProvenance, $requestDto->moveTo);
        $targetMount = $driveMountedNode->agentDriveMount;
        $targetFolderNode = $driveMountedNode->driveNode;
            if ($targetMount->spaceKey !== $agentDriveMount->spaceKey || !$targetFolderNode->isFolder()) {
                throw new BadRequestException('`moveTo` must be a folder of the same space; to another space copy with saveDriveFile.');
            }
        }
        $ownerConsentReservation = $this->requireWriteAllowed($agentDriveMount, $ownerConsentsService, $callProvenance, 'organizeDriveFiles', 'organize a file in a shared drive space', $requestDto->path);
        $previousPath = $driveNodesService->buildPath($driveNode);
        if ($targetFolderNode !== null || ($requestDto->rename !== null && trim($requestDto->rename) !== '')) {
            $driveNode = $driveNodesService->moveNode($driveNode, $targetFolderNode, $requestDto->rename !== null && trim($requestDto->rename) !== '' ? trim($requestDto->rename) : null);
        }
        if ($requestDto->tags !== null || $requestDto->description !== null) {
            $driveNode = $driveNodesService->updateMetadata($driveNode, $requestDto->tags !== null ? array_map('strval', $requestDto->tags) : null, $requestDto->description);
        }
        if ($ownerConsentReservation !== null) {
            $ownerConsentsService->markConsentUsed($callProvenance, $ownerConsentReservation);
        }
        return DriveMcpResponseDto::withLines(
            ['- ' . $cloudDriveAgentDriveStoreService->buildAgentDriveFile($agentDriveMount, $driveNode)->buildLlmLine()],
            'Organized ' . $agentDriveMount->getPathPrefix() . $previousPath . '.'
        );
    }

    /**
     * Moves files or folders of a mounted space to its trash (restorable for 30 days with restoreDriveFiles). Only
     * when the owner asked for it; never empties the trash. In the own drive use rm in the shell and commit.
     *
     * @example {"paths": ["drivespaces/library/Brand/Entwurf.svg"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::DELETE)]
    #[MCPToolRequiresOwnerConfirmation]
    #[MCPToolSummary('Moves files or folders of a mounted drive space to its trash (restorable); only on the owner\'s request.')]
    public function trashDriveFiles(
        DriveFilesTrashRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveNodesService $driveNodesService,
        OwnerConsentsService $ownerConsentsService,
        CloudDriveAgentDriveStoreService $cloudDriveAgentDriveStoreService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $paths = array_values(array_filter(array_map('trim', array_map('strval', $requestDto->paths)), 'strlen'));
        if ($paths === [] || count($paths) > self::MAX_TRASH_PATHS) {
            throw new BadRequestException(sprintf('trashDriveFiles needs 1 to %d `paths`.', self::MAX_TRASH_PATHS));
        }
        $resultLines = [];
        foreach ($paths as $path) {
            $driveMountedNode = $this->requireNodeOfArgument($agentDriveStoresService, $cloudDriveAgentDriveStoreService, $callProvenance, $path);
        $agentDriveMount = $driveMountedNode->agentDriveMount;
        $driveNode = $driveMountedNode->driveNode;
            $ownerConsentReservation = $this->requireWriteAllowed($agentDriveMount, $ownerConsentsService, $callProvenance, 'trashDriveFiles', 'trash a file of a shared drive space', $path);
            $driveNodesService->trashNode($driveNode);
            if ($ownerConsentReservation !== null) {
                $ownerConsentsService->markConsentUsed($callProvenance, $ownerConsentReservation);
            }
            $resultLines[] = '- trashed ' . $path . ' · restore with ' . $driveNode->buildReference();
        }
        return DriveMcpResponseDto::withLines($resultLines);
    }

    /**
     * Restores trashed files or folders by reference to their folder (under a new name when the old one is taken).
     *
     * @example {"references": ["drive/812"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Restores trashed drive files or folders by reference.')]
    public function restoreDriveFiles(
        DriveFilesRestoreRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveNodesService $driveNodesService,
        DriveSpacesService $driveSpacesService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall($callProvenance);
        $resultLines = [];
        foreach ($requestDto->references as $reference) {
            if (preg_match('#^drive/(\d+)$#', trim((string)$reference), $referenceMatches) !== 1) {
                throw new BadRequestException(sprintf('"%s" is no reference drive/<id>.', $reference));
            }
            $driveNode = $driveNodesService->find((int)$referenceMatches[1], false);
            $agentDriveMount = $driveNode instanceof DriveNode ? $this->findMountOfSpace($agentDriveMounts, (int)$driveNode->driveSpaceId) : null;
            if (!$driveNode instanceof DriveNode || $agentDriveMount === null) {
                throw new NotFoundException(sprintf('%s is not in a mounted drive.', $reference));
            }
            if ($agentDriveMount->getEffectiveWriteMode() === AgentDriveMount::MODE_READ_ONLY) {
                throw new ForbiddenException(sprintf('%s is read-only for you.', rtrim($agentDriveMount->getPathPrefix(), '/') ?: 'The own drive'));
            }
            $driveNode = $driveNodesService->restoreNode($driveNode);
            $resultLines[] = '- restored ' . $agentDriveMount->getPathPrefix() . $driveNodesService->buildPath($driveNode);
        }
        return DriveMcpResponseDto::withLines($resultLines);
    }

    /**
     * Shows the owner a link that opens a folder or a search in the drive screen. Use it when the owner should look
     * at files themselves; never write a URL.
     *
     * @example {"path": "reports"}
     * @example {"path": "drivespaces/library", "query": "Logo", "title": "Logos in der Bibliothek"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Shows a link that opens a drive folder or search in the drive screen.')]
    public function showDriveLocation(
        DriveLocationShowRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        DriveNodesService $driveNodesService,
        AIConversationMessagesService $aIConversationMessagesService,
        CloudDriveAgentDriveStoreService $cloudDriveAgentDriveStoreService,
        AgentToolCallProvenance $callProvenance
    ): DriveMcpResponseDto {
        $driveMountedNode = $this->requireNodeOfArgument($agentDriveStoresService, $cloudDriveAgentDriveStoreService, $callProvenance, (string)($requestDto->path ?? ''));
        $agentDriveMount = $driveMountedNode->agentDriveMount;
        $driveNode = $driveMountedNode->driveNode;
        if (!$driveNode->isFolder()) {
            throw new BadRequestException('showDriveLocation opens a folder; a file is shown with showDriveFiles.');
        }
        $driveLocationWidget = new DriveLocationConversationWidget();
        $driveLocationWidget->driveSpaceId = (int)$driveNode->driveSpaceId;
        $driveLocationWidget->driveNodeId = $driveNode->isRoot() ? null : (int)$driveNode->id;
        $driveLocationWidget->path = $driveNodesService->buildPath($driveNode);
        $driveLocationWidget->query = $requestDto->query !== null && trim($requestDto->query) !== '' ? mb_substr(trim($requestDto->query), 0, 200) : null;
        $driveLocationWidget->title = $requestDto->title !== null && trim($requestDto->title) !== '' ? mb_substr(trim($requestDto->title), 0, 160) : null;
        $driveLocationWidget->widgetSelfHydrationKey = $callProvenance->agentSpace?->getWidgetSelfHydrationKey();
        $conversationWidgetDelivery = $aIConversationMessagesService->appendWidget($callProvenance, $driveLocationWidget);
        $dictationNote = $driveLocationWidget->buildDictationNote($conversationWidgetDelivery, 'Say where the files are in one short sentence.');
        return DriveMcpResponseDto::withLines([], $dictationNote ?? 'The link is shown. Write one short line about it; do not write a URL.');
    }

    /**
     * The mount and the node a path or reference names.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     */
    protected function requireNodeOfArgument(
        AgentDriveStoresService $agentDriveStoresService,
        CloudDriveAgentDriveStoreService $cloudDriveAgentDriveStoreService,
        AgentToolCallProvenance $callProvenance,
        string $argument
    ): DriveMountedNode {
        $agentDrivePath = $agentDriveStoresService->resolveDrivePath($agentDriveStoresService->findMountsOfCall($callProvenance), $argument);
        $agentDriveMount = $agentDrivePath->agentDriveMount;
        if ($agentDriveMount === null || $agentDriveMount->storeName !== CloudDriveAgentDriveStoreService::STORE_NAME) {
            throw new BadRequestException(sprintf('"%s" is no path of the drive.', $argument));
        }
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        $driveSpace = $driveSpacesService->requireSpaceWithRight((int)$agentDriveMount->spaceKey, DBDriveSpace::RIGHT_READ);
        $driveNode = $cloudDriveAgentDriveStoreService->findNodeOfPath($driveSpace, $agentDrivePath);
        if ($driveNode === null) {
            throw new NotFoundException(sprintf('"%s" does not exist in the drive (commit a new file first, or check the path with listDriveFiles).', $argument));
        }
        $driveMountedNode = new DriveMountedNode();
        $driveMountedNode->agentDriveMount = $agentDriveMount;
        $driveMountedNode->driveNode = $driveNode;
        return $driveMountedNode;
    }

    /**
     * The mode check of a write tool: READ_ONLY refuses, WRITE_WITH_CONSENT asks the owner (the reservation to mark
     * used), READ_WRITE writes.
     *
     * @throws ForbiddenException
     */
    protected function requireWriteAllowed(
        AgentDriveMount $agentDriveMount,
        OwnerConsentsService $ownerConsentsService,
        AgentToolCallProvenance $callProvenance,
        string $toolName,
        string $actionKind,
        string $targetPath
    ): ?OwnerConsentReservation {
        $writeMode = $agentDriveMount->getEffectiveWriteMode();
        if ($writeMode === AgentDriveMount::MODE_READ_ONLY) {
            throw new ForbiddenException(sprintf('%s is read-only for you: nothing was changed.', rtrim($agentDriveMount->getPathPrefix(), '/') ?: 'The own drive'));
        }
        if ($writeMode !== AgentDriveMount::MODE_WRITE_WITH_CONSENT) {
            return null;
        }
        return $ownerConsentsService->requireOwnerConsentForStep($callProvenance, OwnerConsentRequest::forAction(
            $toolName,
            $actionKind,
            $targetPath . ' in ' . $agentDriveMount->title,
            'people of that space (' . ($agentDriveMount->audienceDescription ?? 'outside the staff') . ') see the change',
            $toolName . '|' . $targetPath
        ));
    }

    /** The working copy of the own drive for the call's sandbox; null without own drive or workspace key */
    protected function findOwnWorkingCopy(AgentDriveMounts $agentDriveMounts, AgentToolCallProvenance $callProvenance): ?DriveWorkingCopy
    {
        $ownDriveMount = $agentDriveMounts->findOwnDrive();
        if ($ownDriveMount === null || $ownDriveMount->storeName !== CloudDriveAgentDriveStoreService::STORE_NAME || $callProvenance->workspaceKey === null) {
            return null;
        }
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        $driveSpace = $driveSpacesService->findSpaceWithRight((int)$ownDriveMount->spaceKey, DBDriveSpace::RIGHT_WRITE);
        if (!$driveSpace instanceof DriveSpace) {
            return null;
        }
        /** @var DriveWorkingCopiesService $driveWorkingCopiesService */
        $driveWorkingCopiesService = DDDService::instance()->getService(DriveWorkingCopiesService::class);
        return $driveWorkingCopiesService->findOrCreateWorkingCopy($driveSpace, $callProvenance->workspaceKey);
    }

    /**
     * @param string[] $resolveArguments
     * @throws BadRequestException
     */
    protected function parseResolutions(array $resolveArguments): DriveCommitResolutions
    {
        $driveCommitResolutions = new DriveCommitResolutions();
        foreach ($resolveArguments as $resolveArgument) {
            $resolveParts = explode('=', (string)$resolveArgument, 2);
            $resolutionParts = explode(':', trim($resolveParts[1] ?? ''), 2);
            $resolution = strtolower(trim($resolutionParts[0]));
            if (count($resolveParts) !== 2 || !in_array($resolution, DriveCommitResolution::RESOLUTIONS, true)) {
                throw new BadRequestException(sprintf('"%s": resolve as `<path>=mine`, `<path>=theirs` or `<path>=both:<new name>`.', $resolveArgument));
            }
            $driveCommitResolution = new DriveCommitResolution();
            $driveCommitResolution->path = trim($resolveParts[0]);
            $driveCommitResolution->resolution = $resolution;
            $driveCommitResolution->bothName = isset($resolutionParts[1]) ? trim($resolutionParts[1]) : null;
            $driveCommitResolutions->add($driveCommitResolution);
        }
        return $driveCommitResolutions;
    }

    /**
     * The mounts of this store, narrowed to one space (`own` or a mount name).
     */
    protected function findCloudDriveMounts(AgentDriveMounts $agentDriveMounts, ?string $spaceFilter): AgentDriveMounts
    {
        $cloudDriveMounts = new AgentDriveMounts();
        foreach ($agentDriveMounts->getElements() as $agentDriveMount) {
            if ($agentDriveMount->storeName !== CloudDriveAgentDriveStoreService::STORE_NAME) {
                continue;
            }
            if ($spaceFilter !== null && $spaceFilter !== '' && !($spaceFilter === self::OWN_SPACE_FILTER ? $agentDriveMount->isOwnDrive : $agentDriveMount->mountName === $spaceFilter)) {
                continue;
            }
            $cloudDriveMounts->add($agentDriveMount);
        }
        return $cloudDriveMounts;
    }

    protected function findMountOfSpace(AgentDriveMounts $agentDriveMounts, int $driveSpaceId): ?AgentDriveMount
    {
        foreach ($agentDriveMounts->getElements() as $agentDriveMount) {
            if ($agentDriveMount->storeName === CloudDriveAgentDriveStoreService::STORE_NAME && (int)$agentDriveMount->spaceKey === $driveSpaceId) {
                return $agentDriveMount;
            }
        }
        return null;
    }

    /**
     * Live nodes of a space with the tag.
     */
    protected function findNodesByTag(int $driveSpaceId, string $tag, int $limit): DriveNodes
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        $repoClass = $driveNodesService->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.trashedAt IS NULL")
            ->andWhere("{$alias}.tags LIKE :tagPattern")
            ->setParameter('driveSpaceId', $driveSpaceId)
            ->setParameter('tagPattern', '%,' . addcslashes(mb_strtolower(trim($tag)), '%_\\') . ',%')
            ->setMaxResults($limit);
        $driveNodes = $repoClass->find($queryBuilder, false);
        return $driveNodes instanceof DriveNodes ? $driveNodes : new DriveNodes();
    }
}
