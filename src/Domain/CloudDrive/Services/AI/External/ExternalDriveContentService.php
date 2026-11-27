<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI\External;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentRead;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveDownload;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItems;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Drives\AgentDriveFileContent;
use DDD\Domain\AI\Libs\Connections\AgentConnectionDriveReach;
use DDD\Domain\AI\Libs\Connections\AgentConnectionRootedDriveCall;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveScopeService;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalFolderNames;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalItem;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalItems;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalRendition;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveTypePoliciesService;
use DDD\Domain\CloudDrive\Services\External\DriveExternalItemsService;
use DDD\Domain\CloudDrive\Services\External\DriveExternalRenditionsService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The content of an EXTERNAL space ({@see ExternalDriveSpacesService}): the metadata mirror (surrogate ids for the
 * sandbox runner, unique filesystem names per folder, the provider's revision) and materialised bytes. Every provider call runs through the AgentConnections gate (level READ, the roots of the
 * acting account's reach, the audit) with the connection's own token; bytes pass the drive's intake (type policy,
 * malware scan) into a blob of the space before anything serves them. Native documents appear as read-only views:
 * `Name.gdoc.md` (structured read), `Name.gsheet.csv` (first sheet), `Name.gslides.md`.
 */
class ExternalDriveContentService extends Service
{
    /** @var int a folder listing older than this is fetched again */
    public const int FOLDER_REFRESH_SECONDS = 120;

    public const int MAXIMUM_FOLDER_ITEMS = 2000;

        public const int MAXIMUM_READ_CHARACTERS = 200000;

    /** @var array<string, string> the view suffix of a native document in listings and the sandbox */
    public const array VIEW_SUFFIX_BY_KIND = [
        AgentConnectionDriveItem::KIND_GOOGLE_DOC => '.gdoc.md',
        AgentConnectionDriveItem::KIND_GOOGLE_SHEET => '.gsheet.csv',
        AgentConnectionDriveItem::KIND_GOOGLE_SLIDES => '.gslides.md',
    ];

    /** The children of a folder of the space (`''` = its root), fetched again when the mirror is older than the refresh time */
    public function listFolder(DriveSpace $driveSpace, AgentConnectionDriveReach $driveReach, string $folderPath, int $actingAccountId): DriveExternalItems
    {
        $folderItem = $folderPath === '' ? $this->findOrCreateRootItem($driveSpace) : $this->requireItemByPath($driveSpace, $driveReach, $folderPath, $actingAccountId);
        if (!$folderItem->isFolder()) {
            $singleItem = DriveExternalItems::newInstance();
            $singleItem->add($folderItem);
            return $singleItem;
        }
        $children = $this->findChildren($driveSpace, (string)$folderItem->providerItemId);
        $syncedAt = $folderItem->syncedAt?->getTimestamp() ?? 0;
        if ($syncedAt < (new DateTime())->getTimestamp() - static::FOLDER_REFRESH_SECONDS) {
            $children = $this->refreshFolder($driveSpace, $driveReach, $folderItem, $actingAccountId);
        }
        return $children;
    }

    /** The item at a path below the space root; the folders on the way are fetched when the mirror does not know them yet */
    public function requireItemByPath(DriveSpace $driveSpace, AgentConnectionDriveReach $driveReach, string $path, int $actingAccountId): DriveExternalItem
    {
        $currentItem = $this->findOrCreateRootItem($driveSpace);
        foreach (array_values(array_filter(explode('/', trim($path, '/')), 'strlen')) as $segment) {
            if (!$currentItem->isFolder()) {
                throw new NotFoundException(sprintf('"%s" does not exist in the external drive.', $path));
            }
            $childItem = $this->findChildByFilesystemName($driveSpace, (string)$currentItem->providerItemId, $segment);
            if ($childItem === null && $currentItem->syncedAt === null) {
                $this->refreshFolder($driveSpace, $driveReach, $currentItem, $actingAccountId);
                $childItem = $this->findChildByFilesystemName($driveSpace, (string)$currentItem->providerItemId, $segment);
            }
            if ($childItem === null) {
                throw new NotFoundException(sprintf('"%s" does not exist in the external drive (or is outside the released folders).', $path));
            }
            $currentItem = $childItem;
        }
        return $currentItem;
    }

    public function findItemById(DriveSpace $driveSpace, int $driveExternalItemId): ?DriveExternalItem
    {
        $driveExternalItem = $this->findItemWithoutRights($driveExternalItemId);
        return $driveExternalItem instanceof DriveExternalItem && (int)$driveExternalItem->driveSpaceId === (int)$driveSpace->id ? $driveExternalItem : null;
    }

    /** Lists a folder at the provider (all pages up to the cap) into the mirror; children that went are dropped */
    public function refreshFolder(DriveSpace $driveSpace, AgentConnectionDriveReach $driveReach, DriveExternalItem $folderItem, int $actingAccountId): DriveExternalItems
    {
        $seenProviderIds = [];
        $folderNames = DriveExternalFolderNames::newInstance();
        $pageToken = null;
        do {
            /** @var AgentConnectionDriveItems $pageItems */
            $pageItems = $this->runProviderRead($driveReach, $actingAccountId, AgentConnectionAction::OPERATION_LIST, 'drive.listFolder', (string)$folderItem->providerItemId, $driveReach->driveAdapter, 'listFolderChildren', [(string)$folderItem->providerItemId, $pageToken, 500]);
            foreach ($pageItems->getElements() as $driveItem) {
                $seenProviderIds[$driveItem->itemId] = true;
                $this->upsertMirrorItem($driveSpace, $driveItem, $folderItem, $folderNames);
            }
            $pageToken = $pageItems->nextPageToken;
        } while ($pageToken !== null && count($seenProviderIds) < static::MAXIMUM_FOLDER_ITEMS);
        $children = DriveExternalItems::newInstance();
        foreach ($this->findChildren($driveSpace, (string)$folderItem->providerItemId)->getElements() as $childItem) {
            if (!isset($seenProviderIds[(string)$childItem->providerItemId])) {
                $this->deleteMirrorSubtree($driveSpace, $childItem);
                continue;
            }
            $children->add($childItem);
        }
        $folderItem->syncedAt = new DateTime();
        $folderItem->updatePartialIgnoringRights('syncedAt');
        return $children;
    }

    /**
     * The bytes of an item at its current revision in our store: a binary as it is, a native document as its view
     * (markdown or CSV). Cached per item, revision and format; the bytes pass the intake (type policy, malware scan)
     * into a blob of the space and are served only when RELEASED.
     *
     * @throws BadRequestException a blocked or unscanned rendition
     */
    public function materialize(DriveSpace $driveSpace, AgentConnectionDriveReach $driveReach, DriveExternalItem $driveExternalItem, int $actingAccountId): DriveExternalRendition
    {
        if ($driveExternalItem->isFolder()) {
            throw new BadRequestException('A folder has no content.');
        }
        $format = match ((string)$driveExternalItem->kind) {
            AgentConnectionDriveItem::KIND_GOOGLE_DOC, AgentConnectionDriveItem::KIND_GOOGLE_SLIDES => DriveExternalRendition::FORMAT_MARKDOWN,
            AgentConnectionDriveItem::KIND_GOOGLE_SHEET => DriveExternalRendition::FORMAT_CSV,
            AgentConnectionDriveItem::KIND_GOOGLE_OTHER => throw new BadRequestException(sprintf('"%s" is a native type without a view here.', $driveExternalItem->displayName)),
            default => DriveExternalRendition::FORMAT_ORIGINAL,
        };
        $existingRendition = $this->findRendition($driveExternalItem, $format);
        if ($existingRendition !== null) {
            return $existingRendition;
        }
        $driveDownload = $this->fetchContent($driveReach, $driveExternalItem, $format, $actingAccountId);
        $content = $driveDownload->content;
        $driveBlob = $this->storeThroughIntake($driveSpace, $content, $driveDownload->contentType, (string)$driveExternalItem->filesystemName);
        if ($driveBlob->state !== DriveBlob::STATE_RELEASED) {
            throw new BadRequestException(sprintf('"%s" was not released by the intake (%s): %s', $driveExternalItem->displayName, $driveBlob->state, (string)$driveBlob->stateReason));
        }
        $driveExternalRendition = DriveExternalRendition::newInstance();
        $driveExternalRendition->driveExternalItemId = (int)$driveExternalItem->id;
        $driveExternalRendition->providerRevision = (string)$driveExternalItem->providerRevision;
        $driveExternalRendition->format = $format;
        $driveExternalRendition->contentType = $driveBlob->contentType;
        $driveExternalRendition->sha256 = $driveBlob->sha256;
        $driveExternalRendition->sizeBytes = strlen($content);
        $driveExternalRendition->storageKey = $driveBlob->storageKey;
        $driveExternalRendition->driveBlobId = (int)$driveBlob->id;
        $driveExternalRendition->fileName = $driveExternalItem->filesystemName;
        /** @var DriveExternalRendition $storedRendition */
        $storedRendition = $this->storeWithoutRights($driveExternalRendition);
        return $storedRendition;
    }

    /** The content of an item for the agent's document tools: the native view as text, a binary's released bytes */
    public function readForAgent(DriveSpace $driveSpace, AgentConnectionDriveReach $driveReach, DriveExternalItem $driveExternalItem, int $maximumBytes, int $actingAccountId): AgentDriveFileContent
    {
        $driveExternalRendition = $this->materialize($driveSpace, $driveReach, $driveExternalItem, $actingAccountId);
        if ((int)$driveExternalRendition->sizeBytes > $maximumBytes) {
            throw new BadRequestException(sprintf('"%s" has %d MB; the agent reads documents up to %d MB.', $driveExternalItem->displayName, (int)ceil((int)$driveExternalRendition->sizeBytes / 1048576), intdiv($maximumBytes, 1048576)));
        }
        $contentBytes = $this->getDriveBlobsService()->getObjectStorage()->getObjectContent((string)$driveExternalRendition->storageKey);
        if ($contentBytes === null) {
            throw new NotFoundException(sprintf('The stored content of "%s" is missing.', $driveExternalItem->displayName));
        }
        $agentDriveFileContent = AgentDriveFileContent::fromBytes($contentBytes, (string)$driveExternalRendition->fileName, $driveExternalRendition->contentType);
        $agentDriveFileContent->reference = $driveExternalItem->buildReference();
        return $agentDriveFileContent;
    }

    public function findOrCreateRootItem(DriveSpace $driveSpace): DriveExternalItem
    {
        $rootItemId = $this->getSpacesService()->findRootItemIdOfSpace($driveSpace);
        if ($rootItemId === null) {
            throw new NotFoundException('This space is no external drive.');
        }
        $rootItem = $this->findItemByProviderId($driveSpace, $rootItemId);
        if ($rootItem !== null) {
            return $rootItem;
        }
        $rootItem = DriveExternalItem::newInstance();
        $rootItem->driveSpaceId = (int)$driveSpace->id;
        $rootItem->providerItemId = $rootItemId;
        $rootItem->parentProviderItemId = null;
        $rootItem->displayName = (string)$driveSpace->title;
        $rootItem->filesystemName = '';
        $rootItem->path = '';
        $rootItem->kind = AgentConnectionDriveItem::KIND_FOLDER;
        /** @var DriveExternalItem $storedRoot */
        $storedRoot = $this->storeWithoutRights($rootItem);
        return $storedRoot;
    }

    public function findChildren(DriveSpace $driveSpace, string $parentProviderItemId): DriveExternalItems
    {
        $repository = $this->getItemsService()->getEntitySetRepoClassInstance();
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("$alias.driveSpaceId = :driveSpaceId")->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->andWhere("$alias.parentProviderItemId = :parentProviderItemId")->setParameter('parentProviderItemId', $parentProviderItemId)
            ->orderBy("$alias.filesystemName", 'ASC')->setMaxResults(static::MAXIMUM_FOLDER_ITEMS);
        $children = $this->findWithoutRights($repository, $queryBuilder);
        return $children instanceof DriveExternalItems ? $children : DriveExternalItems::newInstance();
    }

    /** Every mirrored item of the space (the sandbox base map), folders first */
    public function findAllItems(DriveSpace $driveSpace, int $maximumItems): DriveExternalItems
    {
        $repository = $this->getItemsService()->getEntitySetRepoClassInstance();
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("$alias.driveSpaceId = :driveSpaceId")->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->andWhere("$alias.parentProviderItemId IS NOT NULL")
            ->orderBy("$alias.path", 'ASC')->setMaxResults($maximumItems);
        $items = $this->findWithoutRights($repository, $queryBuilder);
        return $items instanceof DriveExternalItems ? $items : DriveExternalItems::newInstance();
    }

    public function findRendition(DriveExternalItem $driveExternalItem, string $format): ?DriveExternalRendition
    {
        $repository = $this->getRenditionsService()->getEntitySetRepoClassInstance();
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("$alias.driveExternalItemId = :driveExternalItemId")->setParameter('driveExternalItemId', (int)$driveExternalItem->id)
            ->andWhere("$alias.providerRevision = :providerRevision")->setParameter('providerRevision', (string)$driveExternalItem->providerRevision)
            ->andWhere("$alias.format = :format")->setParameter('format', $format)->setMaxResults(1);
        $renditions = $this->findWithoutRights($repository, $queryBuilder);
        $rendition = $renditions?->first();
        return $rendition instanceof DriveExternalRendition ? $rendition : null;
    }

    /** `$folderNames`: the filesystem names given in this folder during this pass; the item's name is added */
    protected function upsertMirrorItem(DriveSpace $driveSpace, AgentConnectionDriveItem $driveItem, DriveExternalItem $parentItem, DriveExternalFolderNames $folderNames): DriveExternalItem
    {
        $driveExternalItem = $this->findItemByProviderId($driveSpace, $driveItem->itemId) ?? DriveExternalItem::newInstance();
        $isNew = !isset($driveExternalItem->id);
        $previousRevision = (string)$driveExternalItem->providerRevision;
        $driveExternalItem->driveSpaceId = (int)$driveSpace->id;
        $driveExternalItem->providerItemId = $driveItem->itemId;
        $driveExternalItem->parentProviderItemId = (string)$parentItem->providerItemId;
        $driveExternalItem->displayName = mb_substr($driveItem->name, 0, 512);
        $driveExternalItem->filesystemName = $this->buildUniqueFilesystemName($driveItem, $folderNames);
        $folderNames->take($driveExternalItem->filesystemName);
        $driveExternalItem->path = ltrim((string)$parentItem->path . '/' . $driveExternalItem->filesystemName, '/');
        $driveExternalItem->mimeType = mb_substr($driveItem->mimeType, 0, 255);
        $driveExternalItem->kind = $driveItem->kind;
        $driveExternalItem->sizeBytes = $driveItem->sizeBytes;
        $driveExternalItem->md5Checksum = $driveItem->md5Checksum;
        $driveExternalItem->providerRevision = $driveItem->getRevisionKey();
        $driveExternalItem->surrogateVersion = $isNew ? 1 : ((int)$driveExternalItem->surrogateVersion + ($previousRevision !== $driveExternalItem->providerRevision ? 1 : 0));
        $driveExternalItem->canEdit = $driveItem->canEdit;
        $driveExternalItem->canComment = $driveItem->canComment;
        $driveExternalItem->canShare = $driveItem->canShare;
        $driveExternalItem->canTrash = $driveItem->canTrash;
        $driveExternalItem->webViewLink = $driveItem->webViewLink;
        $driveExternalItem->modifiedAt = $driveItem->modifiedAt;
        /** @var DriveExternalItem $storedItem */
        $storedItem = $this->storeWithoutRights($driveExternalItem);
        return $storedItem;
    }

    /** The name in the filesystem: the provider's name made safe, a native view's suffix, a short id on a clash */
    protected function buildUniqueFilesystemName(AgentConnectionDriveItem $driveItem, DriveExternalFolderNames $folderNames): string
    {
        $safeName = trim((string)preg_replace('/[\/\\\\\x00-\x1F]+/', '_', $driveItem->name));
        $safeName = $safeName === '' || $safeName === '.' || $safeName === '..' ? 'unnamed' : mb_substr($safeName, 0, 200);
        $suffix = static::VIEW_SUFFIX_BY_KIND[$driveItem->kind] ?? '';
        $candidate = $safeName . $suffix;
        if ($folderNames->isTaken($candidate)) {
            $shortId = substr(hash('sha256', $driveItem->itemId), 0, 6);
            $extensionPosition = $suffix === '' ? strrpos($safeName, '.') : false;
            $candidate = $extensionPosition !== false && $extensionPosition > 0
                ? substr($safeName, 0, $extensionPosition) . ' (' . $shortId . ')' . substr($safeName, $extensionPosition)
                : $safeName . ' (' . $shortId . ')' . $suffix;
        }
        return $candidate;
    }

    protected function deleteMirrorSubtree(DriveSpace $driveSpace, DriveExternalItem $driveExternalItem): void
    {
        foreach ($this->findChildren($driveSpace, (string)$driveExternalItem->providerItemId)->getElements() as $childItem) {
            $this->deleteMirrorSubtree($driveSpace, $childItem);
        }
        $this->deleteWithoutRights($driveExternalItem);
    }

    /** The bytes of the item and their content type: a native document's view, a binary as the provider holds it */
    protected function fetchContent(AgentConnectionDriveReach $driveReach, DriveExternalItem $driveExternalItem, string $format, int $actingAccountId): AgentConnectionDriveDownload
    {
        $providerItemId = (string)$driveExternalItem->providerItemId;
        if ($driveExternalItem->kind === AgentConnectionDriveItem::KIND_GOOGLE_DOC || $driveExternalItem->kind === AgentConnectionDriveItem::KIND_GOOGLE_SLIDES) {
            $methodName = $driveExternalItem->kind === AgentConnectionDriveItem::KIND_GOOGLE_DOC ? 'readGoogleDoc' : 'readSlides';
            /** @var AgentConnectionDocumentRead $documentRead */
            $documentRead = $this->runProviderRead($driveReach, $actingAccountId, AgentConnectionAction::OPERATION_READ, 'drive.materialize', $providerItemId, $driveReach->documentsAdapter, $methodName, [$providerItemId, static::MAXIMUM_READ_CHARACTERS]);
            $documentDownload = AgentConnectionDriveDownload::newInstance();
            $documentDownload->itemId = $providerItemId;
            $documentDownload->revisionKey = (string)$documentRead->revisionId;
            $documentDownload->fileName = (string)$driveExternalItem->filesystemName;
            $documentDownload->contentType = 'text/markdown';
            $documentDownload->content = "# {$documentRead->title}\n\nrevision: {$documentRead->revisionId}\n\n" . $documentRead->markdown;
            return $documentDownload;
        }
        $exportMimeType = $format === DriveExternalRendition::FORMAT_CSV ? 'text/csv' : null;
        /** @var AgentConnectionDriveDownload $driveDownload */
        $driveDownload = $this->runProviderRead($driveReach, $actingAccountId, AgentConnectionAction::OPERATION_READ, 'drive.materialize', $providerItemId, $driveReach->driveAdapter, 'downloadDriveItem', [$providerItemId, $exportMimeType, ExternalDriveSpacesService::EXTERNAL_MAXIMUM_FILE_BYTES]);
        return $driveDownload;
    }

    /** The bytes into a blob of the space through the drive's intake (type policy of the space, malware scan) */
    protected function storeThroughIntake(DriveSpace $driveSpace, string $content, string $contentType, string $fileName): DriveBlob
    {
        $driveBlobsService = $this->getDriveBlobsService();
        $driveUploadRequest = DriveUploadRequest::newInstance();
        $driveUploadRequest->sizeBytes = strlen($content);
        $driveUploadRequest->contentType = $contentType !== '' ? $contentType : null;
        $driveUploadRequest->sha256 = hash('sha256', $content);
        $driveUploadRequest->fileName = $fileName;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveUploadReservation = $driveBlobsService->reserveUpload($driveSpace, $driveUploadRequest);
            $driveBlob = $driveBlobsService->find($driveUploadReservation->driveBlobId, false);
            if (!$driveBlob instanceof DriveBlob) {
                throw new NotFoundException('The reserved blob is missing.');
            }
            if ($driveUploadReservation->isContentKnown) {
                return $driveBlob;
            }
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $content);
            rewind($stream);
            $driveBlobsService->writeUploadContent($driveBlob, $stream, strlen($content));
            fclose($stream);
            $driveBlob = $driveBlobsService->completeUpload($driveBlob);
            /** @var DriveTypePoliciesService $driveTypePoliciesService */
            $driveTypePoliciesService = DDDService::instance()->getService(DriveTypePoliciesService::class);
            return $driveBlobsService->ingestBlob($driveBlob, true, $driveTypePoliciesService->buildTypePolicyForSpace($driveSpace, DriveSpace::AUDIENCE_INTERNAL), $fileName);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The gated operation of one provider read: it carries the tool call the reach serves, so the space of the call
     * decides which release counts and the audience rule sees the conversation.
     */
    public function buildReadOperation(AgentConnectionDriveReach $driveReach, int $actingAccountId, string $operation, string $toolName): AgentConnectionOperation
    {
        return AgentConnectionOperation::create($driveReach->agentConnection, AgentConnectionPermission::CAPABILITY_FILES, $operation, $toolName, $actingAccountId, $driveReach->callProvenance);
    }

    /** @param array<int, mixed> $arguments */
    protected function runProviderRead(AgentConnectionDriveReach $driveReach, int $actingAccountId, string $operation, string $toolName, ?string $itemId, object $callTarget, string $methodName, array $arguments): mixed
    {
        $agentConnectionOperation = $this->buildReadOperation($driveReach, $actingAccountId, $operation, $toolName);
        /** @var AgentConnectionOperationsService $agentConnectionOperationsService */
        $agentConnectionOperationsService = DDDService::instance()->getService(AgentConnectionOperationsService::class);
        /** @var AgentConnectionDriveScopeService $agentConnectionDriveScopeService */
        $agentConnectionDriveScopeService = DDDService::instance()->getService(AgentConnectionDriveScopeService::class);
        return $agentConnectionOperationsService->runRead(
            $agentConnectionOperation,
            new AgentConnectionRootedDriveCall($agentConnectionDriveScopeService, $driveReach->driveAdapter, $driveReach->agentConnection, $driveReach->agentConnectionAccess, $itemId, $callTarget, $methodName, $arguments),
            (string)$itemId
        );
    }

    protected function findItemByProviderId(DriveSpace $driveSpace, string $providerItemId): ?DriveExternalItem
    {
        $repository = $this->getItemsService()->getEntitySetRepoClassInstance();
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("$alias.driveSpaceId = :driveSpaceId")->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->andWhere("$alias.providerItemId = :providerItemId")->setParameter('providerItemId', $providerItemId)->setMaxResults(1);
        $items = $this->findWithoutRights($repository, $queryBuilder);
        $item = $items?->first();
        return $item instanceof DriveExternalItem ? $item : null;
    }

    protected function findChildByFilesystemName(DriveSpace $driveSpace, string $parentProviderItemId, string $filesystemName): ?DriveExternalItem
    {
        foreach ($this->findChildren($driveSpace, $parentProviderItemId)->getElements() as $childItem) {
            if ((string)$childItem->filesystemName === $filesystemName) {
                return $childItem;
            }
        }
        return null;
    }

    /*
     * The mirror tables are system rows (their DB rights refuse everyone): the space was authorized through its
     * connection before any of these calls.
     */

    protected function findWithoutRights(DBEntitySet $repository, DoctrineQueryBuilder $queryBuilder): ?EntitySet
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function findItemWithoutRights(int $driveExternalItemId): ?DriveExternalItem
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getItemsService()->find($driveExternalItemId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function storeWithoutRights(Entity $entity): Entity
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $entity->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function deleteWithoutRights(Entity $entity): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $entity->delete();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function getSpacesService(): ExternalDriveSpacesService
    {
        /** @var ExternalDriveSpacesService $externalDriveSpacesService */
        $externalDriveSpacesService = DDDService::instance()->getService(ExternalDriveSpacesService::class);
        return $externalDriveSpacesService;
    }

    protected function getItemsService(): DriveExternalItemsService
    {
        /** @var DriveExternalItemsService $driveExternalItemsService */
        $driveExternalItemsService = DDDService::instance()->getService(DriveExternalItemsService::class);
        return $driveExternalItemsService;
    }

    protected function getRenditionsService(): DriveExternalRenditionsService
    {
        /** @var DriveExternalRenditionsService $driveExternalRenditionsService */
        $driveExternalRenditionsService = DDDService::instance()->getService(DriveExternalRenditionsService::class);
        return $driveExternalRenditionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }
}
