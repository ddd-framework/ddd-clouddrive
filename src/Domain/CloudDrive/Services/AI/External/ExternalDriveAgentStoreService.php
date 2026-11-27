<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI\External;

use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFile;
use DDD\Domain\AI\Entities\Drives\AgentDriveFileContent;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveListing;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Libs\Connections\AgentConnectionDriveReach;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalItem;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The EXTERNAL spaces inside the agent's drive mounts: one read-only mount per space the acting account reaches through
 * its drive connections ({@see ExternalDriveSpacesService::findReachableSpacesOfAccount()}). Every list and read checks
 * the reach again (connection usable, access of the account, FILES level, the space's root inside the roots of that
 * reach) and goes to the provider through {@see ExternalDriveContentService} (the gate, the roots walk, the intake).
 * Mount keys are `external/<space id>`, references `external/<mirror item id>`.
 */
class ExternalDriveAgentStoreService extends Service
{
    public const string SPACE_KEY_PREFIX = 'external/';

    public static function isExternalMount(?AgentDriveMount $agentDriveMount): bool
    {
        return $agentDriveMount !== null && str_starts_with($agentDriveMount->spaceKey, static::SPACE_KEY_PREFIX);
    }

    /** One read-only mount per reachable EXTERNAL space of the acting account; none without an account */
    public function buildMountsOfCall(AgentToolCallProvenance $callProvenance): AgentDriveMounts
    {
        $agentDriveMounts = new AgentDriveMounts();
        $accountId = $this->findActingAccountId($callProvenance);
        if ($accountId === null || $callProvenance->workspaceKey !== null) {
            // an eval case with a scratch drive never reaches a provider
            return $agentDriveMounts;
        }
        $externalDriveSpacesService = $this->getSpacesService();
        foreach ($externalDriveSpacesService->findReachableSpacesOfAccount($accountId, AgentConnectionReach::ofCall($callProvenance))->getElements() as $driveSpace) {
            $agentDriveMount = new AgentDriveMount();
            $agentDriveMount->storeName = CloudDriveAgentDriveStoreService::STORE_NAME;
            $agentDriveMount->mountName = $externalDriveSpacesService->buildMountName($driveSpace);
            $agentDriveMount->spaceKey = static::SPACE_KEY_PREFIX . $driveSpace->id;
            $agentDriveMount->title = (string)$driveSpace->title;
            $agentDriveMount->mode = AgentDriveMount::MODE_READ_ONLY;
            $agentDriveMount->isOutwardVisible = true;
            $agentDriveMount->audienceDescription = 'a connected external drive: read here; edit native documents with the document tools of the connection';
            $agentDriveMounts->add($agentDriveMount);
        }
        return $agentDriveMounts;
    }

    /** @throws NotFoundException|ForbiddenException|BadRequestException */
    public function listDriveFiles(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, ?string $cursor, int $limit): AgentDriveListing
    {
        $accountId = $this->requireActingAccountId($callProvenance);
        $driveSpace = $this->requireSpaceOfPath($agentDrivePath);
        $driveReach = $this->getSpacesService()->requireReachOfSpace($driveSpace, $accountId, $callProvenance);
        $agentDriveMount = $agentDrivePath->agentDriveMount;
        $contentService = $this->getContentService();
        $driveExternalItem = $this->resolveItem($driveSpace, $driveReach, $agentDrivePath, $accountId);
        $agentDriveListing = new AgentDriveListing();
        $agentDriveListing->agentDriveMount = $agentDriveMount;
        $agentDriveListing->agentDriveFiles = new AgentDriveFiles();
        $agentDriveListing->path = $agentDriveMount->getPathPrefix() . (string)$driveExternalItem->path;
        if (!$driveExternalItem->isFolder()) {
            $agentDriveFile = $this->buildAgentDriveFile($agentDriveMount, $driveExternalItem);
            $agentDriveListing->agentDriveFiles->add($agentDriveFile);
            return $agentDriveListing;
        }
        $children = $contentService->listFolder($driveSpace, $driveReach, (string)$driveExternalItem->path, $accountId);
        $agentDriveListing->totalCount = $children->count();
        $offset = $cursor !== null && ctype_digit($cursor) ? (int)$cursor : 0;
        $position = 0;
        foreach ($children->getElements() as $childItem) {
            if ($position >= $offset && $position < $offset + $limit) {
                $agentDriveFile = $this->buildAgentDriveFile($agentDriveMount, $childItem);
                $agentDriveListing->agentDriveFiles->add($agentDriveFile);
            }
            $position++;
        }
        if ($offset + $limit < $position) {
            $agentDriveListing->nextCursor = (string)($offset + $limit);
        }
        return $agentDriveListing;
    }

    public function findDriveFile(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath): ?AgentDriveFile
    {
        $accountId = $this->requireActingAccountId($callProvenance);
        $driveSpace = $this->requireSpaceOfPath($agentDrivePath);
        $driveReach = $this->getSpacesService()->requireReachOfSpace($driveSpace, $accountId, $callProvenance);
        $driveExternalItem = $this->resolveItem($driveSpace, $driveReach, $agentDrivePath, $accountId);
        return $driveExternalItem->parentProviderItemId !== null ? $this->buildAgentDriveFile($agentDrivePath->agentDriveMount, $driveExternalItem) : null;
    }

    /** @throws NotFoundException|ForbiddenException|BadRequestException */
    public function readDriveFileContent(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, int $maxBytes): AgentDriveFileContent
    {
        $accountId = $this->requireActingAccountId($callProvenance);
        $driveSpace = $this->requireSpaceOfPath($agentDrivePath);
        $driveReach = $this->getSpacesService()->requireReachOfSpace($driveSpace, $accountId, $callProvenance);
        $driveExternalItem = $this->resolveItem($driveSpace, $driveReach, $agentDrivePath, $accountId);
        if ($driveExternalItem->isFolder()) {
            throw new NotFoundException(sprintf('%s is a folder; name a file in it (listDriveFiles shows them).', $agentDrivePath->getWorkspacePath()));
        }
        $agentDriveFileContent = $this->getContentService()->readForAgent($driveSpace, $driveReach, $driveExternalItem, $maxBytes, $accountId);
        $agentDriveFileContent->workspacePath = $agentDrivePath->agentDriveMount->getPathPrefix() . (string)$driveExternalItem->path;
        return $agentDriveFileContent;
    }

    public function buildAgentDriveFile(AgentDriveMount $agentDriveMount, DriveExternalItem $driveExternalItem): AgentDriveFile
    {
        $agentDriveFile = new AgentDriveFile();
        $agentDriveFile->storeName = CloudDriveAgentDriveStoreService::STORE_NAME;
        $agentDriveFile->reference = $driveExternalItem->buildReference();
        $agentDriveFile->path = $agentDriveMount->getPathPrefix() . (string)$driveExternalItem->path;
        $agentDriveFile->name = (string)$driveExternalItem->filesystemName;
        $agentDriveFile->isFolder = $driveExternalItem->isFolder();
        $agentDriveFile->sizeBytes = $driveExternalItem->isFolder() ? null : $driveExternalItem->sizeBytes;
        $agentDriveFile->contentType = $driveExternalItem->mimeType;
        $agentDriveFile->modifiedAt = $driveExternalItem->modifiedAt;
        $agentDriveFile->versionNumber = $driveExternalItem->surrogateVersion;
        $agentDriveFile->state = $driveExternalItem->isNativeDocument() ? 'native document (read view; edit with the document tools)' : 'external, read-only';
        if ((string)$driveExternalItem->webViewLink !== '') {
            $agentDriveFile->setOpenLinkIfSafe($driveExternalItem->webViewLink);
        }
        return $agentDriveFile;
    }

    /**
     * The space of the path's mount (its reach is checked by the caller on every call).
     *
     * @throws NotFoundException|BadRequestException
     */
    protected function requireSpaceOfPath(AgentDrivePath $agentDrivePath): DriveSpace
    {
        $agentDriveMount = $agentDrivePath->agentDriveMount;
        if (!static::isExternalMount($agentDriveMount)) {
            throw new BadRequestException(sprintf('"%s" is no path of an external drive.', $agentDrivePath->argument));
        }
        $driveSpaceId = (int)substr($agentDriveMount->spaceKey, strlen(static::SPACE_KEY_PREFIX));
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveSpace = $driveSpacesService->find($driveSpaceId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$driveSpace instanceof DriveSpace) {
            throw new NotFoundException('This external drive no longer exists.');
        }
        return $driveSpace;
    }

    /** @throws ForbiddenException */
    protected function requireActingAccountId(AgentToolCallProvenance $callProvenance): int
    {
        $accountId = $this->findActingAccountId($callProvenance);
        if ($accountId === null) {
            throw new ForbiddenException('No account for this call.');
        }
        return $accountId;
    }

    /** The mirror item a path or an `external/<id>` reference of the space names */
    protected function resolveItem(DriveSpace $driveSpace, AgentConnectionDriveReach $driveReach, AgentDrivePath $agentDrivePath, int $accountId): DriveExternalItem
    {
        $contentService = $this->getContentService();
        if ($agentDrivePath->reference !== null) {
            if (preg_match('#^external/(\d+)$#', $agentDrivePath->reference, $referenceMatches) !== 1) {
                throw new NotFoundException(sprintf('"%s" is no reference of an external drive.', $agentDrivePath->reference));
            }
            $driveExternalItem = $contentService->findItemById($driveSpace, (int)$referenceMatches[1]);
            if ($driveExternalItem === null) {
                throw new NotFoundException('This item is no longer in the external drive.');
            }
            return $driveExternalItem;
        }
        return $contentService->requireItemByPath($driveSpace, $driveReach, $agentDrivePath->pathInMount, $accountId);
    }

    protected function findActingAccountId(AgentToolCallProvenance $callProvenance): ?int
    {
        $authAccountId = AuthService::instance()->getAccount()?->id;
        return $callProvenance->getConversationOwnerAccountIdOrAuthAccountId($authAccountId !== null ? (int)$authAccountId : null);
    }

    protected function getSpacesService(): ExternalDriveSpacesService
    {
        /** @var ExternalDriveSpacesService $externalDriveSpacesService */
        $externalDriveSpacesService = DDDService::instance()->getService(ExternalDriveSpacesService::class);
        return $externalDriveSpacesService;
    }

    protected function getContentService(): ExternalDriveContentService
    {
        /** @var ExternalDriveContentService $externalDriveContentService */
        $externalDriveContentService = DDDService::instance()->getService(ExternalDriveContentService::class);
        return $externalDriveContentService;
    }
}
