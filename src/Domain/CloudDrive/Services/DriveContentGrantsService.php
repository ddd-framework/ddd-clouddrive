<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Content\DriveFileCard;
use DDD\Domain\CloudDrive\Entities\Content\DriveFileCards;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The one gate for file content: a short-lived URL per version and purpose, under the reader's rights. People read
 * only released content (DOWNLOAD as attachment, INLINE for a preview, never inline for HTML or SVG). A working copy
 * gets its own unreleased content back (WORKSPACE_RESTORE): staged blobs of its entries and pending versions of its
 * space, never downloadable by people.
 */
class DriveContentGrantsService extends Service
{
    /** @var string[] Types never served inline: a browser would run them in the store's origin */
    public const array NEVER_INLINE_CONTENT_TYPES = ['text/html', 'image/svg+xml', 'application/xhtml+xml', 'application/xml', 'text/xml', 'application/javascript', 'text/javascript'];

    /**
     * The content URL of a file's released version (the current one when no version is given).
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function createContentGrant(DriveNode $driveNode, string $purpose = DriveContentGrant::PURPOSE_DOWNLOAD, ?DriveFileVersion $driveFileVersion = null): DriveContentGrant
    {
        if (!in_array($purpose, [DriveContentGrant::PURPOSE_DOWNLOAD, DriveContentGrant::PURPOSE_INLINE], true)) {
            throw new BadRequestException('Content for people is granted for DOWNLOAD or INLINE.');
        }
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_READ);
        if (!$driveNode->isFile()) {
            throw new BadRequestException('Only a file has content.');
        }
        $driveFileVersion ??= $driveNode->currentVersionId !== null ? $this->getDriveVersionsService()->find((int)$driveNode->currentVersionId, false) : null;
        if (!$driveFileVersion instanceof DriveFileVersion || $driveFileVersion->driveNodeId !== $driveNode->id) {
            throw new NotFoundException('The file has no released version yet (it waits for its scan).');
        }
        $driveBlob = $this->getDriveBlobsService()->find((int)$driveFileVersion->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob || !$driveBlob->isReadableByPeople()) {
            throw new ForbiddenException($driveBlob?->state === DriveBlob::STATE_BLOCKED
                ? 'The file was blocked by the malware scan.'
                : 'The version is not released yet (it waits for its scan).');
        }
        $asAttachment = $purpose === DriveContentGrant::PURPOSE_DOWNLOAD
            || in_array(strtolower((string)$driveBlob->contentType), self::NEVER_INLINE_CONTENT_TYPES, true);
        return $this->buildGrant($driveBlob, (string)$driveNode->name, $purpose, $asAttachment);
    }

    /**
     * The content URL of an unreleased blob for the working copy that holds it: a staged blob of one of its entries,
     * or the blob of a version in its space. Needs the write right on the space.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws InternalErrorException
     */
    public function createWorkspaceRestoreGrant(DriveWorkingCopy $driveWorkingCopy, int $driveBlobId, string $fileName): DriveContentGrant
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveWorkingCopy->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $driveBlob = $this->getDriveBlobsService()->find($driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob || $driveBlob->driveSpaceId !== $driveWorkingCopy->driveSpaceId
            || in_array($driveBlob->state, [DriveBlob::STATE_UPLOADING, DriveBlob::STATE_PURGING, DriveBlob::STATE_FAILED], true)
        ) {
            throw new NotFoundException(sprintf('Blob %d is not restorable into this working copy.', $driveBlobId));
        }
        return $this->buildGrant($driveBlob, $fileName, DriveContentGrant::PURPOSE_WORKSPACE_RESTORE, true);
    }

    /**
     * File cards for references (`drive/<id>`): facts and short-lived content URLs under the viewer's rights; a
     * reference the viewer may not read is left out.
     *
     * @param string[] $references
     */
    public function findFileCards(array $references): DriveFileCards
    {
        $driveFileCards = new DriveFileCards();
        foreach (array_slice(array_values(array_unique($references)), 0, 100) as $reference) {
            if (preg_match('#^drive/(\d+)$#', (string)$reference, $referenceMatches) !== 1) {
                continue;
            }
            $driveNode = $this->getDriveNodesService()->find((int)$referenceMatches[1], false);
            if (!$driveNode instanceof DriveNode || $driveNode->isTrashed()) {
                continue;
            }
            $driveFileCard = new DriveFileCard();
            $driveFileCard->reference = $driveNode->buildReference();
            $driveFileCard->driveSpaceId = (int)$driveNode->driveSpaceId;
            $driveFileCard->name = (string)$driveNode->name;
            $driveFileCard->path = $this->getDriveNodesService()->buildPath($driveNode);
            $driveFileCard->isFolder = $driveNode->isFolder();
            $driveFileCard->sizeBytes = $driveNode->isFile() ? $driveNode->sizeBytes : null;
            $driveFileCard->contentType = $driveNode->contentType;
            $driveFileCard->modifiedAt = $driveNode->getLastEditTime();
            $driveFileCard->versionNumber = $driveNode->versionNumber;
            $driveFileCard->childCount = $driveNode->isFolder() ? (int)$driveNode->childCount : null;
            $driveFileCard->state = $driveNode->isContentUntrusted ? 'written by a third party' : null;
            if ($driveNode->isFile()) {
                try {
                    $downloadGrant = $this->createContentGrant($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
                    $inlineGrant = $this->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
                    $driveFileCard->downloadUrl = $downloadGrant->url;
                    $driveFileCard->inlineUrl = $inlineGrant->url;
                    $driveFileCard->urlsExpireAt = $downloadGrant->expiresAt;
                    if (str_starts_with((string)$driveNode->contentType, 'image/') && !in_array(strtolower((string)$driveNode->contentType), self::NEVER_INLINE_CONTENT_TYPES, true)) {
                        $driveFileCard->thumbnailUrl = $inlineGrant->url;
                    }
                } catch (ForbiddenException|NotFoundException $grantRefusal) {
                    $driveFileCard->state = str_contains($grantRefusal->getMessage(), 'blocked') ? 'blocked' : 'pending scan';
                }
            }
            $driveFileCards->add($driveFileCard);
        }
        return $driveFileCards;
    }

    protected function buildGrant(DriveBlob $driveBlob, string $fileName, string $purpose, bool $asAttachment): DriveContentGrant
    {
        $lifetimeSeconds = max(30, (int)Config::get('CloudDrive.settings.downloadUrlLifetimeSeconds'));
        $driveContentGrant = new DriveContentGrant();
        $driveContentGrant->purpose = $purpose;
        $driveContentGrant->url = $this->getDriveBlobsService()->createDownloadRequestUrl($driveBlob, $fileName, $asAttachment, $lifetimeSeconds);
        $driveContentGrant->expiresAt = DateTime::fromTimestamp(time() + $lifetimeSeconds);
        $driveContentGrant->fileName = $fileName;
        $driveContentGrant->sizeBytes = (int)$driveBlob->sizeBytes;
        $driveContentGrant->contentType = $driveBlob->contentType;
        $driveContentGrant->sha256 = $driveBlob->sha256;
        return $driveContentGrant;
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
}
