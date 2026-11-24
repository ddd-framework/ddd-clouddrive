<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Content;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the backend serves for a content grant after every check passed: which stored object, under which name and
 * headers. Internal: the storage key never leaves the server.
 */
class DriveContentDelivery extends ValueObject
{
    public int $driveNodeId = 0;

    public int $driveFileVersionId = 0;

    public string $purpose = DriveContentGrant::PURPOSE_DOWNLOAD;

    /** @var string The object to stream (the blob, or the sanitised copy of an SVG) */
    public string $storageKey = '';

    public string $fileName = '';

    public ?int $sizeBytes = null;

    /** @var string|null The blob's type (from its bytes) */
    public ?string $contentType = null;

    public ?string $sha256 = null;

    /** @var string|null The type it is shown inline as (vetted: raster, PDF, plain text, a sanitised SVG); null = attachment */
    public ?string $inlineContentType = null;

    public function isInline(): bool
    {
        return $this->inlineContentType !== null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->driveFileVersionId . '-' . $this->purpose);
    }
}
