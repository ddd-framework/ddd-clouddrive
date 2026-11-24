<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Media\DriveMediaImport;
use DDD\Domain\CloudDrive\Entities\Media\DriveMediaImportResult;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The bridge from the drive to the media library: a drive file (an image) taken into the library as a media item.
 * The drive may call the media library, the media library never knows the drive, and media never stores its bytes on
 * the drive's object store (the library copies them into its own).
 *
 * The media library's logic (descriptions, usage slots, look-alikes) is host code today, so this is a port: a host
 * with a media library overrides this service at the same path ({@see isMediaLibraryAvailable()} true,
 * {@see importFileIntoMediaLibrary()}); the default has no library and refuses. The agent tool
 * `importDriveFileToMediaLibrary` (`clouddrive.files`) and any host screen call it.
 */
class DriveMediaLibraryBridgeService extends Service
{
    /** @var string[] The types the library takes from the drive: raster images (decoded only by the library's own pinned decoder) */
    public const array IMPORTABLE_CONTENT_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Whether the host has a media library to take drive files into */
    public function isMediaLibraryAvailable(): bool
    {
        return false;
    }

    /**
     * Takes the file's released version into the media library. The caller checked the read right on the file's
     * space; the host checks its own right on the library and reads the bytes with {@see saveReleasedContentToFile()}.
     */
    public function importFileIntoMediaLibrary(DriveNode $driveNode, DriveMediaImport $driveMediaImport): DriveMediaImportResult
    {
        return DriveMediaImportResult::refused('this application has no media library to take drive files into');
    }

    /** Why the file cannot go into the media library; null when it can */
    public function findImportRefusalReason(DriveNode $driveNode): ?string
    {
        if (!$driveNode->isFile()) {
            return 'only a file can go into the media library';
        }
        if ($driveNode->isTrashed()) {
            return 'the file is in the trash';
        }
        if ($driveNode->currentVersionId === null) {
            return 'the file has no released version yet (the malware scan has not decided)';
        }
        if (!in_array((string)$driveNode->contentType, static::IMPORTABLE_CONTENT_TYPES, true)) {
            return sprintf('the media library takes JPEG, PNG, GIF and WebP images, the file is %s', $driveNode->contentType ?? 'of an unknown type');
        }
        return null;
    }

    /** Writes the bytes of the file's released version into a local file; false when there is none or the store failed */
    public function saveReleasedContentToFile(DriveNode $driveNode, string $filePath): bool
    {
        if ($driveNode->currentVersionId === null) {
            return false;
        }
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        $driveFileVersion = $driveVersionsService->find((int)$driveNode->currentVersionId, false);
        $driveBlob = $driveFileVersion instanceof DriveFileVersion ? $driveBlobsService->find((int)$driveFileVersion->driveBlobId, false) : null;
        if (!$driveBlob instanceof DriveBlob || $driveBlob->state !== DriveBlob::STATE_RELEASED || $driveBlob->storageKey === null) {
            return false;
        }
        return $driveBlobsService->getObjectStorage()->saveObjectToFile((string)$driveBlob->storageKey, $filePath);
    }
}
