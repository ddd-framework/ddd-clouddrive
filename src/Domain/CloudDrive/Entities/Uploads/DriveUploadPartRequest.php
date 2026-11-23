<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Uploads;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\ObjectStorage\ObjectStoragePresignedRequest;

/** The presigned PUT of one part of a multipart upload */
class DriveUploadPartRequest extends ValueObject
{
    public int $partNumber = 0;

    public ?ObjectStoragePresignedRequest $request = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->partNumber);
    }
}
