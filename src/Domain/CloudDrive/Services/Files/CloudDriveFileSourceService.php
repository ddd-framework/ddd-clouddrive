<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\Files;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFactsSet;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileSource;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The drive as a file source: `drive/<nodeId>` names a file node, read as its current released version. A file
 * exists for a caller with the read right on its space; a folder, a trashed file and a file without a released
 * version (still in its scan, or refused by it) do not. The bytes come from the object store through the backend
 * after the same checks as a download; no key of the store is ever listed or handed out, so objects of the store
 * that belong to no drive file are out of reach.
 */
class CloudDriveFileSourceService extends Service implements FileSource
{
    public const string SOURCE_NAME = 'drive';

    /** @var string[] Root folders whose files are a person's own data, unless `CloudDrive.settings.personalFolders` names some: none */
    public const array DEFAULT_PERSONAL_FOLDERS = [];

    /** @var int Facts of at most this many files per call */
    public const int MAX_FACTS_PER_CALL = 200;

    public function getSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    public function findFilesFacts(array $ids): FileFactsSet
    {
        $fileFactsSet = new FileFactsSet();
        $readableSpacesById = [];
        foreach (array_slice(array_values($ids), 0, static::MAX_FACTS_PER_CALL) as $id) {
            $driveNode = $this->findReadableFileNode((string)$id);
            if ($driveNode === null) {
                continue;
            }
            $driveSpaceId = (int)$driveNode->driveSpaceId;
            if (!array_key_exists($driveSpaceId, $readableSpacesById)) {
                $readableSpacesById[$driveSpaceId] = $this->getDriveSpacesService()->findSpaceWithRight($driveSpaceId, DBDriveSpace::RIGHT_READ);
            }
            $driveSpace = $readableSpacesById[$driveSpaceId];
            if (!$driveSpace instanceof DriveSpace) {
                continue;
            }
            $fileFacts = $this->buildFileFacts($driveNode, $driveSpace);
            $fileFactsSet->add($fileFacts);
        }
        return $fileFactsSet;
    }

    public function readFileContent(string $id, int $maxBytes): FileContent
    {
        $driveNode = $this->findReadableFileNode($id);
        $driveSpace = $driveNode !== null
            ? $this->getDriveSpacesService()->findSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_READ)
            : null;
        if ($driveNode === null || !$driveSpace instanceof DriveSpace) {
            throw new NotFoundException(sprintf('%s/%s was not found.', self::SOURCE_NAME, $id));
        }
        // the checks of a download: read right, trash rule, a released blob (never one waiting for or refused by its scan)
        $driveContentDelivery = $this->getDriveContentGrantsService()->resolveContentDelivery($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
        if ($driveContentDelivery->sizeBytes !== null && $driveContentDelivery->sizeBytes > $maxBytes) {
            throw new BadRequestException(sprintf(
                '%s has %d bytes; at most %d are read here.',
                $driveNode->buildReference(),
                $driveContentDelivery->sizeBytes,
                $maxBytes
            ));
        }
        $contentBytes = $this->getDriveBlobsService()->getObjectStorage()->getObjectContent($driveContentDelivery->storageKey);
        if ($contentBytes === null) {
            throw new NotFoundException(sprintf('The stored content of %s is missing.', $driveNode->buildReference()));
        }
        if (strlen($contentBytes) > $maxBytes) {
            throw new BadRequestException(sprintf('%s is larger than %d bytes.', $driveNode->buildReference(), $maxBytes));
        }
        $fileFacts = $this->buildFileFacts($driveNode, $driveSpace);
        $fileFacts->sizeBytes = strlen($contentBytes);
        $fileFacts->contentType = $driveContentDelivery->contentType ?? $fileFacts->contentType;
        $fileFacts->sha256 = $driveContentDelivery->sha256 ?? $fileFacts->sha256;
        $fileFacts->family = FileFamily::fromContentType($fileFacts->contentType, $fileFacts->name);
        return FileContent::fromFactsAndContent($fileFacts, $contentBytes);
    }

    /**
     * Who sees a file of the space at this path: a person's own data below a personal folder (wherever it lies, so
     * a copy of a copy stays personal), people outside the staff in a shared space, else the staff.
     */
    public function findAudienceOfPath(DriveSpace $driveSpace, string $path): string
    {
        $firstSegment = explode('/', trim($path, '/'))[0];
        if ($firstSegment !== '' && in_array($firstSegment, $this->getPersonalFolders(), true)) {
            return FileAudience::PERSONAL;
        }
        return $driveSpace->isShared() ? FileAudience::SHARED : FileAudience::INTERNAL;
    }

    /** @return string[] The root folders whose files are a person's own data */
    public function getPersonalFolders(): array
    {
        $personalFolders = Config::get('CloudDrive.settings.personalFolders');
        return is_array($personalFolders) ? array_values(array_map('strval', $personalFolders)) : static::DEFAULT_PERSONAL_FOLDERS;
    }

    /** The live file node an id names when it has a released version; null for anything else */
    protected function findReadableFileNode(string $id): ?DriveNode
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            return null;
        }
        $driveNode = $this->getDriveNodesService()->find((int)$id, false);
        if (!$driveNode instanceof DriveNode || !$driveNode->isFile() || $driveNode->isTrashed() || $driveNode->currentVersionId === null) {
            return null;
        }
        return $driveNode;
    }

    protected function buildFileFacts(DriveNode $driveNode, DriveSpace $driveSpace): FileFacts
    {
        $driveNodesService = $this->getDriveNodesService();
        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference(self::SOURCE_NAME, (string)$driveNode->id);
        $fileFacts->name = (string)$driveNode->name;
        $fileFacts->contentType = $driveNode->contentType;
        $fileFacts->family = FileFamily::fromContentType($driveNode->contentType, (string)$driveNode->name);
        $fileFacts->sizeBytes = $driveNode->sizeBytes;
        $fileFacts->sha256 = $driveNode->sha256;
        $fileFacts->audience = $this->findAudienceOfPath($driveSpace, $driveNodesService->buildPath($driveNode));
        $fileFacts->isContentUntrusted = (bool)$driveNode->isContentUntrusted || $driveSpace->isShared();
        $fileFacts->containerLabel = $driveSpace->buildDisplayTitle();
        if ($driveNodesService->getAdminLinkTemplate() !== null) {
            $fileFacts->setOpenLinkIfSafe($driveNodesService->buildAdminLink($driveNode));
        }
        return $fileFacts;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        return $driveNodesService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        /** @var DriveContentGrantsService $driveContentGrantsService */
        $driveContentGrantsService = DDDService::instance()->getService(DriveContentGrantsService::class);
        return $driveContentGrantsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }
}
