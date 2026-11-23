<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Content\DriveFileCard;
use DDD\Domain\CloudDrive\Entities\Content\DriveFileCards;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
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
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;

/**
 * The one gate for file content: a short-lived URL per version and purpose, under the reader's rights. People read
 * only released content (DOWNLOAD as attachment, INLINE for a preview of JPEG, PNG, GIF or WebP only — the type comes
 * from the bytes at ingest; anything else is an attachment even when INLINE is asked). A working copy
 * gets its own unreleased content back (WORKSPACE_RESTORE): staged blobs of its entries and pending versions of its
 * space, never downloadable by people.
 */
class DriveContentGrantsService extends Service
{
    public const string SVG_CONTENT_TYPE = 'image/svg+xml';

    /** @var string[] Types never served inline (kept for callers; inline is decided by an allow-list now) */
    public const array NEVER_INLINE_CONTENT_TYPES = UntrustedContentHeaders::ACTIVE_CONTENT_TYPES;

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
        if (!in_array($purpose, DriveContentGrant::PEOPLE_PURPOSES, true)) {
            throw new BadRequestException('Content for people is granted for DOWNLOAD, INLINE or THUMBNAIL.');
        }
        $driveSpacesService = $this->getDriveSpacesService();
        $driveSpacesService->requireSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_READ);
        if (!$driveNode->isFile()) {
            throw new BadRequestException('Only a file has content.');
        }
        if (!$this->isVariantAllowed($driveNode, $purpose, $driveSpacesService->getCurrentAudience())) {
            throw new ForbiddenException($driveNode->isTrashed()
                ? 'The file is in the trash: only the staff can download it.'
                : sprintf('The %s variant of this file is not available.', strtolower($purpose)));
        }
        $driveFileVersion ??= $driveNode->currentVersionId !== null ? $this->getDriveVersionsService()->find((int)$driveNode->currentVersionId, false) : null;
        if (!$driveFileVersion instanceof DriveFileVersion || $driveFileVersion->driveNodeId !== $driveNode->id) {
            throw new NotFoundException('The file has no released version yet (it waits for its scan).');
        }
        $driveBlob = $this->getDriveBlobsService()->find((int)$driveFileVersion->driveBlobId, false);
        if (!$driveBlob instanceof DriveBlob || !$driveBlob->isReadableByPeople()) {
            throw new ForbiddenException(match ($driveBlob?->state) {
                DriveBlob::STATE_BLOCKED => 'The file was blocked by the malware scan.',
                DriveBlob::STATE_SCAN_FAILED => 'The malware scan could not run yet; the file is scanned again shortly.',
                default => 'The version is not released yet (it waits for its scan).',
            });
        }
        if ($purpose !== DriveContentGrant::PURPOSE_DOWNLOAD && UntrustedContentHeaders::normalizeContentType($driveBlob->contentType) === self::SVG_CONTENT_TYPE) {
            $sanitizedSvgGrant = $this->createSanitizedSvgGrant($driveBlob, (string)$driveNode->name, $purpose);
            if ($sanitizedSvgGrant !== null) {
                $sanitizedSvgGrant->driveNodeId = (int)$driveNode->id;
                $sanitizedSvgGrant->driveFileVersionId = (int)$driveFileVersion->id;
                return $sanitizedSvgGrant;
            }
        }
        $asAttachment = $purpose === DriveContentGrant::PURPOSE_DOWNLOAD
            || !UntrustedContentHeaders::isInlineContentType($driveBlob->contentType);
        if ($purpose === DriveContentGrant::PURPOSE_THUMBNAIL && $asAttachment) {
            throw new BadRequestException('Only a JPEG, PNG, GIF or WebP image has a thumbnail.');
        }
        $driveContentGrant = $this->buildGrant($driveBlob, (string)$driveNode->name, $purpose, $asAttachment);
        $driveContentGrant->driveNodeId = (int)$driveNode->id;
        $driveContentGrant->driveFileVersionId = (int)$driveFileVersion->id;
        return $driveContentGrant;
    }

    /**
     * Whether `$audience` may read the `$variant` (DOWNLOAD, INLINE, THUMBNAIL) of a file. Default: every variant of a
     * live file; of a trashed file only the staff's download. Override in the application (same-path subclass) for
     * other rules (e.g. originals of a kind only for the staff).
     */
    public function isVariantAllowed(DriveNode $driveNode, string $variant, string $audience): bool
    {
        if ($driveNode->isTrashed()) {
            return $audience === DriveSpace::AUDIENCE_INTERNAL && $variant === DriveContentGrant::PURPOSE_DOWNLOAD;
        }
        return true;
    }

    /**
     * The inline grant of an SVG's sanitised copy ({@see DriveSvgSanitizer}), made once per blob and kept next to it;
     * null without a sanitiser or when the SVG cannot be made safe (it stays an attachment).
     */
    protected function createSanitizedSvgGrant(DriveBlob $driveBlob, string $fileName, string $purpose): ?DriveContentGrant
    {
        /** @var DriveSvgSanitizersService $driveSvgSanitizersService */
        $driveSvgSanitizersService = DDDService::instance()->getService(DriveSvgSanitizersService::class);
        if (!$driveSvgSanitizersService->hasSvgSanitizer() || (int)$driveBlob->sizeBytes > DriveSvgSanitizersService::MAX_SVG_BYTES) {
            return null;
        }
        $driveBlobsService = $this->getDriveBlobsService();
        $objectStorage = $driveBlobsService->getObjectStorage();
        $sanitizedStorageKey = DriveBlobsService::buildSanitizedSvgStorageKey((string)$driveBlob->storageKey);
        if (!$objectStorage->objectExists($sanitizedStorageKey)) {
            $svgBytes = $objectStorage->getObjectContent((string)$driveBlob->storageKey);
            $sanitizedSvg = $svgBytes !== null ? $driveSvgSanitizersService->sanitizeSvg($svgBytes) : null;
            if ($sanitizedSvg === null) {
                return null;
            }
            $objectStorage->putObjectIfAbsent($sanitizedStorageKey, $sanitizedSvg, self::SVG_CONTENT_TYPE);
        }
        $lifetimeSeconds = max(30, (int)Config::get('CloudDrive.settings.downloadUrlLifetimeSeconds'));
        $driveContentGrant = new DriveContentGrant();
        $driveContentGrant->purpose = $purpose;
        $driveContentGrant->url = $objectStorage->createTemporaryUrl(
            $sanitizedStorageKey,
            $lifetimeSeconds,
            self::SVG_CONTENT_TYPE,
            UntrustedContentHeaders::buildContentDisposition(true, $fileName)
        );
        $driveContentGrant->expiresAt = DateTime::fromTimestamp(time() + $lifetimeSeconds);
        $driveContentGrant->fileName = $fileName;
        $driveContentGrant->contentType = self::SVG_CONTENT_TYPE;
        $driveContentGrant->sha256 = $driveBlob->sha256;
        $driveContentGrant->isInline = true;
        return $driveContentGrant;
    }

    /**
     * The content URL of an unreleased blob for the working copy that holds it: a staged blob of one of its entries,
     * or the blob of a version in its space. Needs the write right on the space. A blob the scan found malware in
     * (BLOCKED) is never restored; one whose scan could not run (SCAN_FAILED) is, into the sandbox only — its bytes came
     * from there, and people still cannot read it.
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
            || in_array($driveBlob->state, [DriveBlob::STATE_UPLOADING, DriveBlob::STATE_PURGING, DriveBlob::STATE_FAILED, DriveBlob::STATE_BLOCKED], true)
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
                    if ($inlineGrant->isInline) {
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
        $driveContentGrant->isInline = !$asAttachment && UntrustedContentHeaders::isInlineContentType($driveBlob->contentType);
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
