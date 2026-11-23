<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Uploads;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\ObjectStorage\ObjectStoragePresignedRequest;

/**
 * A reserved upload: the blob that will hold the bytes and how to send them, either one presigned PUT or the parts of
 * a multipart upload (each part `partSizeBytes`, the last one smaller). The client sends every header of a request
 * unchanged; the server completes the upload itself.
 */
class DriveUploadReservation extends ValueObject
{
    public int $driveBlobId = 0;

    public int $sizeBytes = 0;

    /** @var ObjectStoragePresignedRequest|null The single PUT; null for a multipart upload or reused content */
    public ?ObjectStoragePresignedRequest $uploadRequest = null;

    public bool $isMultipart = false;

    public int $partSizeBytes = 0;

    public int $partCount = 0;

    public ?DriveUploadPartRequests $partRequests = null;

    /** @var bool The space holds the same content already (by its SHA-256): nothing has to be sent */
    public bool $isContentKnown = false;
}
