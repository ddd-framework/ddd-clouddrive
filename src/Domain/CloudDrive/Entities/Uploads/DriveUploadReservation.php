<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Uploads;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\ObjectStorage\ObjectStoragePresignedRequest;

/**
 * A reserved upload: the blob that will hold the bytes and how to send them to the backend — in one request
 * (`PUT …/uploads/{driveBlobId}/content`) or in parts (`PUT …/uploads/{driveBlobId}/parts/{partNumber}`, each part
 * `partSizeBytes`, the last one smaller), then complete it. Only a working copy's runner on the server gets presigned
 * requests (`uploadRequest`, `partRequests`); a client never does.
 */
class DriveUploadReservation extends ValueObject
{
    public int $driveBlobId = 0;

    public int $sizeBytes = 0;

    /** @var ObjectStoragePresignedRequest|null The runner's single presigned PUT (server side only); null for clients */
    public ?ObjectStoragePresignedRequest $uploadRequest = null;

    public bool $isMultipart = false;

    public int $partSizeBytes = 0;

    public int $partCount = 0;

    /** @var DriveUploadPartRequests|null The runner's presigned part PUTs (server side only); null for clients */
    public ?DriveUploadPartRequests $partRequests = null;

    /** @var int[] Parts of a multipart upload the store holds already (a resumed upload sends only the others) */
    public array $uploadedPartNumbers = [];

    /** @var bool The space holds the same content already (by its SHA-256): nothing has to be sent */
    public bool $isContentKnown = false;
}
