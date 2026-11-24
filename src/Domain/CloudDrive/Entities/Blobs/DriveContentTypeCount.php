<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Blobs;

use DDD\Domain\Base\Entities\ValueObject;

/** How many blobs the content type backfill gave one type */
class DriveContentTypeCount extends ValueObject
{
    public string $contentType = '';

    public int $blobCount = 0;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->contentType);
    }
}
