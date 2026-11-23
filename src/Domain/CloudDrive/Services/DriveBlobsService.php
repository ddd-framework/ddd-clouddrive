<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlobs;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveScanResult;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadPartRequest;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadPartRequests;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadRequest;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadReservation;
use DDD\Domain\CloudDrive\Repo\DB\Blobs\DBDriveBlob;
use DDD\Domain\CloudDrive\Repo\DB\Blobs\DBDriveBlobs;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersion;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Throwable;

/**
 * The bytes of a space: reserving an upload against the quota, the presigned requests (create-only, MD5-verified), the
 * completion the server does itself, the ingest (SHA-256 from the stored bytes, the malware scan, dedup inside the
 * space) and the sweep of what nothing references. PHP never proxies bytes except during the ingest's read.
 *
 * Write-once: every blob gets a key of its own (`<space>/<blob id>-<random>`), a presigned PUT carries
 * `If-None-Match: *`, and a multipart upload is completed only by the server and only onto a key without an object
 * (stores ignore `If-None-Match` on the completion).
 *
 * @method DriveBlob|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DBDriveBlob getEntityRepoClassInstance()
 * @method DBDriveBlobs getEntitySetRepoClassInstance()
 */
class DriveBlobsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DriveBlob::class;

    public const int MAX_PARTS = 10000;
    public const int MIN_PART_BYTES = 5 * 1024 * 1024;

    /**
     * Reserves an upload into a space: checks the largest file, reserves the bytes against the quota and answers how to
     * send them. Known content (same SHA-256, released in the space) needs no upload.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function reserveUpload(DriveSpace $driveSpace, DriveUploadRequest $driveUploadRequest, string $initialState = DriveBlob::STATE_UPLOADING): DriveUploadReservation
    {
        $sizeBytes = $driveUploadRequest->sizeBytes;
        if ($sizeBytes < 0) {
            throw new BadRequestException('The size of an upload cannot be negative.');
        }
        if ($sizeBytes > $driveSpace->getEffectiveMaxFileSizeBytes()) {
            throw new BadRequestException(sprintf('The file is larger than the largest file this space takes (%d bytes).', $driveSpace->getEffectiveMaxFileSizeBytes()));
        }
        if ($driveUploadRequest->idempotencyKey !== null) {
            $existingBlob = $this->findBlobByIdempotencyKey($driveSpace, $driveUploadRequest->idempotencyKey);
            if ($existingBlob !== null) {
                return $this->buildReservation($existingBlob);
            }
        }
        if ($driveUploadRequest->sha256 !== null && preg_match('/^[0-9a-f]{64}$/', $driveUploadRequest->sha256) === 1) {
            $releasedBlob = $this->findReleasedBlobBySha256((int)$driveSpace->id, $driveUploadRequest->sha256);
            if ($releasedBlob !== null && (int)$releasedBlob->sizeBytes === $sizeBytes) {
                $driveUploadReservation = new DriveUploadReservation();
                $driveUploadReservation->driveBlobId = (int)$releasedBlob->id;
                $driveUploadReservation->sizeBytes = $sizeBytes;
                $driveUploadReservation->isContentKnown = true;
                return $driveUploadReservation;
            }
        }
        if (!$this->getDriveSpacesService()->reserveBytes($driveSpace, $sizeBytes)) {
            throw new BadRequestException('The space is full: this upload would exceed its quota.');
        }
        $driveBlob = DriveBlob::newInstance();
        $driveBlob->driveSpaceId = (int)$driveSpace->id;
        $driveBlob->state = $initialState;
        $driveBlob->sizeBytes = $sizeBytes;
        $driveBlob->contentType = $driveUploadRequest->contentType;
        $driveBlob->contentMd5 = $driveUploadRequest->contentMd5;
        $driveBlob->sha256 = $driveUploadRequest->sha256;
        $driveBlob->idempotencyKey = $driveUploadRequest->idempotencyKey;
        $driveBlob->storageKey = 'pending/' . bin2hex(random_bytes(16));
        $driveBlob->expiresAt = DateTime::fromTimestamp(time() + 3600 * (int)Config::get('CloudDrive.settings.unfinishedUploadExpiryHours'));
        try {
            /** @var DriveBlob $driveBlob */
            $driveBlob = $driveBlob->update();
            $driveBlob->storageKey = static::buildStorageKey((int)$driveSpace->id, (int)$driveBlob->id);
            if ($sizeBytes >= (int)Config::get('CloudDrive.settings.multipartThresholdBytes')) {
                $driveBlob->multipartUploadId = $this->getObjectStorage()->createMultipartUpload($driveBlob->storageKey, $driveBlob->contentType);
            }
            $driveBlob->updatePartialIgnoringRights('storageKey', 'multipartUploadId');
        } catch (Throwable $throwable) {
            $this->getDriveSpacesService()->releaseBytes($driveSpace, $sizeBytes);
            throw $throwable;
        }
        return $this->buildReservation($driveBlob);
    }

    /** The requests of a reservation (again, e.g. to resume an upload whose URLs expired) */
    public function buildReservation(DriveBlob $driveBlob, ?array $partNumbers = null): DriveUploadReservation
    {
        $driveUploadReservation = new DriveUploadReservation();
        $driveUploadReservation->driveBlobId = (int)$driveBlob->id;
        $driveUploadReservation->sizeBytes = (int)$driveBlob->sizeBytes;
        if ($driveBlob->state !== DriveBlob::STATE_UPLOADING) {
            $driveUploadReservation->isContentKnown = true;
            return $driveUploadReservation;
        }
        $objectStorage = $this->getObjectStorage();
        $lifetimeSeconds = (int)Config::get('CloudDrive.settings.uploadUrlLifetimeSeconds');
        if ($driveBlob->multipartUploadId === null) {
            $driveUploadReservation->uploadRequest = $objectStorage->createTemporaryUploadRequest(
                (string)$driveBlob->storageKey,
                $lifetimeSeconds,
                $driveBlob->contentType,
                true,
                null,
                $driveBlob->contentMd5
            );
            return $driveUploadReservation;
        }
        $partSizeBytes = $this->findPartSizeBytes((int)$driveBlob->sizeBytes);
        $partCount = max(1, (int)ceil((int)$driveBlob->sizeBytes / $partSizeBytes));
        $driveUploadReservation->isMultipart = true;
        $driveUploadReservation->partSizeBytes = $partSizeBytes;
        $driveUploadReservation->partCount = $partCount;
        $driveUploadReservation->partRequests = new DriveUploadPartRequests();
        foreach ($partNumbers ?? range(1, $partCount) as $partNumber) {
            if ($partNumber < 1 || $partNumber > $partCount) {
                continue;
            }
            $driveUploadPartRequest = new DriveUploadPartRequest();
            $driveUploadPartRequest->partNumber = (int)$partNumber;
            $driveUploadPartRequest->request = $objectStorage->createTemporaryUploadPartRequest((string)$driveBlob->storageKey, (string)$driveBlob->multipartUploadId, (int)$partNumber, $lifetimeSeconds);
            $driveUploadReservation->partRequests->add($driveUploadPartRequest);
        }
        return $driveUploadReservation;
    }

    /**
     * The upload's bytes arrived: the server completes a multipart upload (never onto an existing object), reads the
     * stored size and compares it with the announced one. Answers the blob in the state `$nextState` (PENDING for a
     * version, STAGED for a working copy).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function completeUpload(DriveBlob $driveBlob, string $nextState = DriveBlob::STATE_PENDING): DriveBlob
    {
        if ($driveBlob->state !== DriveBlob::STATE_UPLOADING) {
            return $driveBlob;
        }
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveBlob->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
        $objectStorage = $this->getObjectStorage();
        if ($driveBlob->multipartUploadId !== null) {
            $uploadedParts = $objectStorage->listUploadedParts((string)$driveBlob->storageKey, $driveBlob->multipartUploadId);
            $expectedPartCount = max(1, (int)ceil((int)$driveBlob->sizeBytes / $this->findPartSizeBytes((int)$driveBlob->sizeBytes)));
            if ($uploadedParts->count() !== $expectedPartCount || $uploadedParts->getTotalSize() !== (int)$driveBlob->sizeBytes) {
                throw new BadRequestException(sprintf('The upload is incomplete: %d of %d parts, %d of %d bytes.', $uploadedParts->count(), $expectedPartCount, $uploadedParts->getTotalSize(), (int)$driveBlob->sizeBytes));
            }
            if (!$objectStorage->completeMultipartUpload((string)$driveBlob->storageKey, $driveBlob->multipartUploadId, $uploadedParts, true)) {
                throw new InternalErrorException(sprintf('Drive blob %d: an object exists already under its key; the upload was not completed.', (int)$driveBlob->id));
            }
            $driveBlob->multipartUploadId = null;
        }
        $storedObject = $objectStorage->headObject((string)$driveBlob->storageKey);
        if ($storedObject === null) {
            throw new BadRequestException('The upload has not arrived in the store yet.');
        }
        if ((int)$storedObject->size !== (int)$driveBlob->sizeBytes) {
            $this->markFailed($driveBlob, sprintf('stored %d bytes instead of the announced %d', (int)$storedObject->size, (int)$driveBlob->sizeBytes));
            throw new BadRequestException(sprintf('The upload has %d bytes instead of the announced %d.', (int)$storedObject->size, (int)$driveBlob->sizeBytes));
        }
        $driveBlob->state = $nextState;
        $driveBlob->expiresAt = $nextState === DriveBlob::STATE_STAGED ? $driveBlob->expiresAt : null;
        $driveBlob->updatePartialIgnoringRights('state', 'multipartUploadId', 'expiresAt');
        return $driveBlob;
    }

    /**
     * Reads the stored bytes once: SHA-256, the malware scan (unless `$isScanned` is false, e.g. for system folders),
     * then RELEASED or BLOCKED. Equal content released in the space before wins: the version is pointed at that blob
     * and this one is purged. Answers the blob the version keeps.
     *
     * @throws InternalErrorException
     */
    public function ingestBlob(DriveBlob $driveBlob, bool $isScanned = true): DriveBlob
    {
        if (!in_array($driveBlob->state, [DriveBlob::STATE_PENDING, DriveBlob::STATE_STAGED, DriveBlob::STATE_SCAN_FAILED], true)) {
            return $driveBlob;
        }
        $temporaryFilePath = tempnam(sys_get_temp_dir(), 'drive-ingest-');
        try {
            if (!$this->getObjectStorage()->saveObjectToFile((string)$driveBlob->storageKey, $temporaryFilePath)) {
                $this->markFailed($driveBlob, 'the object is missing in the store');
                return $driveBlob;
            }
            $sha256 = (string)hash_file('sha256', $temporaryFilePath);
            if ($driveBlob->sha256 !== null && $driveBlob->sha256 !== '' && $driveBlob->sha256 !== $sha256) {
                $this->markFailed($driveBlob, 'the stored bytes do not match the announced SHA-256');
                return $driveBlob;
            }
            $driveBlob->sha256 = $sha256;
            $releasedBlob = $this->findReleasedBlobBySha256((int)$driveBlob->driveSpaceId, $sha256);
            if ($releasedBlob !== null && $releasedBlob->id !== $driveBlob->id) {
                $this->pointVersionsAtBlob($driveBlob, $releasedBlob);
                $this->markForPurge($driveBlob);
                return $releasedBlob;
            }
            $driveBlob->contentType = static::detectContentTypeOfFile($temporaryFilePath, $driveBlob->contentType);
            $driveScanResult = $isScanned ? $this->scanFile($temporaryFilePath) : DriveScanResult::create(DriveScanResult::OUTCOME_CLEAN);
            $driveBlob->state = match ($driveScanResult->outcome) {
                DriveScanResult::OUTCOME_CLEAN => DriveBlob::STATE_RELEASED,
                DriveScanResult::OUTCOME_INFECTED => DriveBlob::STATE_BLOCKED,
                default => DriveBlob::STATE_SCAN_FAILED,
            };
            $driveBlob->stateReason = $driveScanResult->detail;
            $driveBlob->expiresAt = null;
            $driveBlob->updatePartialIgnoringRights('sha256', 'contentType', 'state', 'stateReason', 'expiresAt');
            return $driveBlob;
        } finally {
            @unlink($temporaryFilePath);
        }
    }

    /**
     * The content of a blob in another (or the same) space, copied inside the store: a released blob of the target
     * with the same SHA-256 is reused, else a new blob is reserved against the target's quota and copied on the
     * server (no byte passes PHP). The copy is PENDING: the target's ingest decides its release.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function copyBlobIntoSpace(DriveBlob $sourceBlob, DriveSpace $targetSpace): DriveBlob
    {
        if ($sourceBlob->sha256 !== null && $sourceBlob->sha256 !== '') {
            $releasedBlob = $this->findReleasedBlobBySha256((int)$targetSpace->id, $sourceBlob->sha256);
            if ($releasedBlob !== null) {
                return $releasedBlob;
            }
        }
        if ((int)$sourceBlob->sizeBytes > $targetSpace->getEffectiveMaxFileSizeBytes()) {
            throw new BadRequestException(sprintf('The file is larger than the largest file the target space takes (%d bytes).', $targetSpace->getEffectiveMaxFileSizeBytes()));
        }
        if (!$this->getDriveSpacesService()->reserveBytes($targetSpace, (int)$sourceBlob->sizeBytes)) {
            throw new BadRequestException('The target space is full: the copy would exceed its quota.');
        }
        $copiedBlob = DriveBlob::newInstance();
        $copiedBlob->driveSpaceId = (int)$targetSpace->id;
        $copiedBlob->state = DriveBlob::STATE_UPLOADING;
        $copiedBlob->sizeBytes = (int)$sourceBlob->sizeBytes;
        $copiedBlob->contentType = $sourceBlob->contentType;
        $copiedBlob->contentMd5 = $sourceBlob->contentMd5;
        $copiedBlob->sha256 = $sourceBlob->sha256;
        $copiedBlob->storageKey = 'pending/' . bin2hex(random_bytes(16));
        try {
            /** @var DriveBlob $copiedBlob */
            $copiedBlob = $copiedBlob->update();
            $copiedBlob->storageKey = static::buildStorageKey((int)$targetSpace->id, (int)$copiedBlob->id);
            if (!$this->getObjectStorage()->copyObject((string)$sourceBlob->storageKey, $copiedBlob->storageKey, true)) {
                throw new InternalErrorException(sprintf('Drive blob %d: an object exists already under its key.', (int)$copiedBlob->id));
            }
            $copiedBlob->state = DriveBlob::STATE_PENDING;
            $copiedBlob->updatePartialIgnoringRights('storageKey', 'state');
        } catch (Throwable $throwable) {
            $this->getDriveSpacesService()->releaseBytes($targetSpace, (int)$sourceBlob->sizeBytes);
            if (isset($copiedBlob->id)) {
                $this->markFailed($copiedBlob, 'the copy failed: ' . $throwable->getMessage());
            }
            throw $throwable;
        }
        return $copiedBlob;
    }

    /**
     * The malware scan of a file by the scanner the setting `malwareScanner` names ({@see DriveMalwareScannersService}):
     * CLEAN, INFECTED (blocked for good) or FAILED (not installed, no signatures, a timeout, no scanner configured;
     * fail closed: never released, scanned again by `app:drive:maintain`). Only `malwareScanner: none` releases
     * unscanned.
     */
    public function scanFile(string $filePath): DriveScanResult
    {
        /** @var DriveMalwareScannersService $driveMalwareScannersService */
        $driveMalwareScannersService = DDDService::instance()->getService(DriveMalwareScannersService::class);
        return $driveMalwareScannersService->scanFile($filePath);
    }

    /**
     * The content type a blob keeps, from its bytes: an allowed raster image (JPEG, PNG, GIF, WebP, HEIC) gets the type
     * its magic bytes name; bytes that only claim an image type get `application/octet-stream`; anything else keeps
     * the declared type (normalised), which is used for attachments only.
     *
     * @param string $filePath server-generated
     * @param string|null $declaredContentType what the uploader said
     * @return string
     */
    public static function detectContentTypeOfFile(string $filePath, ?string $declaredContentType): string
    {
        $detectedMimeType = RasterImageDecoder::detectMimeType(RasterImageDecoder::readHead($filePath));
        if ($detectedMimeType !== null) {
            return $detectedMimeType;
        }
        $normalizedContentType = UntrustedContentHeaders::normalizeContentType($declaredContentType);
        if ($normalizedContentType === '' || str_starts_with($normalizedContentType, 'image/')) {
            return UntrustedContentHeaders::OPAQUE_CONTENT_TYPE;
        }
        return $normalizedContentType;
    }

    /** Blobs whose scan failed, oldest first: the maintenance scans them again */
    public function findBlobsWithFailedScan(int $limit = 100): DriveBlobs
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.state = :state")->setParameter('state', DriveBlob::STATE_SCAN_FAILED)
            ->orderBy("{$alias}.id", 'ASC')->setMaxResults(max(1, $limit));
        $driveBlobs = $repoClass->find($queryBuilder, false);
        return $driveBlobs instanceof DriveBlobs ? $driveBlobs : new DriveBlobs();
    }

    /**
     * Blobs by id (a list's batch lookup), under the caller's rights; missing ids are left out.
     *
     * @param int[] $driveBlobIds
     */
    public function findBlobsByIds(array $driveBlobIds): DriveBlobs
    {
        $driveBlobIds = array_values(array_unique(array_filter(array_map('intval', $driveBlobIds))));
        if ($driveBlobIds === []) {
            return new DriveBlobs();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.id IN (:driveBlobIds)")->setParameter('driveBlobIds', $driveBlobIds);
        $driveBlobs = $repoClass->find($queryBuilder, false);
        return $driveBlobs instanceof DriveBlobs ? $driveBlobs : new DriveBlobs();
    }

    public function findReleasedBlobBySha256(int $driveSpaceId, string $sha256): ?DriveBlob
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.sha256 = :sha256")
            ->andWhere("{$alias}.state = :state")
            ->setParameter('driveSpaceId', $driveSpaceId)
            ->setParameter('sha256', $sha256)
            ->setParameter('state', DriveBlob::STATE_RELEASED)
            ->orderBy("{$alias}.id", 'ASC')
            ->setMaxResults(1);
        $driveBlob = $repoClass->find($queryBuilder, false);
        return $driveBlob instanceof DriveBlob ? $driveBlob : null;
    }

    public function findBlobByIdempotencyKey(DriveSpace $driveSpace, string $idempotencyKey): ?DriveBlob
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.idempotencyKey = :idempotencyKey")
            ->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->setMaxResults(1);
        $driveBlob = $repoClass->find($queryBuilder, false);
        return $driveBlob instanceof DriveBlob ? $driveBlob : null;
    }

    /**
     * A presigned GET of a blob; the purpose was checked by {@see DriveContentGrantsService}. Inline only for the raster
     * allow-list (JPEG, PNG, GIF, WebP); everything else is an attachment, and a type a browser would run (HTML, SVG,
     * XML, JavaScript) is served as `application/octet-stream`.
     */
    public function createDownloadRequestUrl(DriveBlob $driveBlob, string $fileName, bool $asAttachment, int $lifetimeSeconds): string
    {
        $contentType = UntrustedContentHeaders::normalizeContentType($driveBlob->contentType);
        $isInline = !$asAttachment && UntrustedContentHeaders::isInlineContentType($contentType);
        if (!$isInline && ($contentType === '' || in_array($contentType, UntrustedContentHeaders::ACTIVE_CONTENT_TYPES, true))) {
            $contentType = UntrustedContentHeaders::OPAQUE_CONTENT_TYPE;
        }
        $disposition = UntrustedContentHeaders::buildContentDisposition($isInline, $fileName);
        return $this->getObjectStorage()->createTemporaryUrl((string)$driveBlob->storageKey, $lifetimeSeconds, $contentType, $disposition);
    }

    public function markFailed(DriveBlob $driveBlob, string $reason): void
    {
        $driveBlob->state = DriveBlob::STATE_FAILED;
        $driveBlob->stateReason = mb_substr($reason, 0, 1000);
        $driveBlob->updatePartialIgnoringRights('state', 'stateReason');
    }

    /** The sweep deletes the object and the row; the bytes are given back to the quota then */
    public function markForPurge(DriveBlob $driveBlob): void
    {
        $driveBlob->state = DriveBlob::STATE_PURGING;
        $driveBlob->updatePartialIgnoringRights('state');
    }

    /**
     * Deletes the object and the row of a blob nothing references any more and gives its bytes back.
     */
    public function purgeBlob(DriveBlob $driveBlob): void
    {
        $objectStorage = $this->getObjectStorage();
        if ($driveBlob->multipartUploadId !== null) {
            $objectStorage->abortMultipartUpload((string)$driveBlob->storageKey, $driveBlob->multipartUploadId);
        }
        $objectStorage->deleteObject((string)$driveBlob->storageKey);
        $this->getDriveSpacesService()->releaseBytes((int)$driveBlob->driveSpaceId, (int)$driveBlob->sizeBytes);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveBlob->delete();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** `<space id>/<yyyy>/<blob id>-<random>`: unique per blob, never reused */
    public static function buildStorageKey(int $driveSpaceId, int $driveBlobId): string
    {
        return $driveSpaceId . '/' . gmdate('Y') . '/' . $driveBlobId . '-' . bin2hex(random_bytes(8));
    }

    /** The part size: the configured one, raised so that no upload needs more than 10,000 parts */
    public function findPartSizeBytes(int $sizeBytes): int
    {
        $configuredPartBytes = max(self::MIN_PART_BYTES, (int)Config::get('CloudDrive.settings.multipartPartBytes'));
        return max($configuredPartBytes, (int)ceil($sizeBytes / self::MAX_PARTS));
    }

    /**
     * @throws InternalErrorException
     */
    public function getObjectStorage(): ObjectStorage
    {
        $storeName = (string)(Config::get('CloudDrive.settings.objectStorageName') ?: 'clouddrive');
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        $objectStorage = $objectStorageService->getObjectStorage($storeName);
        if ($objectStorage === null) {
            throw new InternalErrorException(sprintf('The cloud drive needs the object store "%s" (Common.ObjectStorage, OBJECT_STORAGE_%s_*).', $storeName, strtoupper($storeName)));
        }
        return $objectStorage;
    }

    /** Points every version of the duplicate blob at the released one */
    protected function pointVersionsAtBlob(DriveBlob $duplicateBlob, DriveBlob $releasedBlob): void
    {
        DBDriveBlob::createQueryBuilder()
            ->update(DBDriveFileVersion::BASE_ORM_MODEL, 'version')
            ->set('version.driveBlobId', ':releasedBlobId')
            ->where('version.driveBlobId = :duplicateBlobId')
            ->setParameter('releasedBlobId', (int)$releasedBlob->id)
            ->setParameter('duplicateBlobId', (int)$duplicateBlob->id)
            ->getQuery()->execute();
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }
}
