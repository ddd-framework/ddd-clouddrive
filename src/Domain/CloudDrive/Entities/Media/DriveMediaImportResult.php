<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Media;

use DDD\Domain\Base\Entities\ValueObject;

/** What the media library made of a drive file: the media item, or why it took nothing */
class DriveMediaImportResult extends ValueObject
{
    /** @var int|null The media item in the library (new, or the one that already holds these bytes) */
    public ?int $mediaItemId = null;

    /** @var string|null The media item's title */
    public ?string $title = null;

    /** @var bool The library held these bytes already: no new item */
    public bool $isAlreadyInLibrary = false;

    /** @var string|null Why nothing was taken; null when an item exists */
    public ?string $refusalReason = null;

    public static function refused(string $refusalReason): static
    {
        $driveMediaImportResult = new static();
        $driveMediaImportResult->refusalReason = $refusalReason;
        return $driveMediaImportResult;
    }

    public function isImported(): bool
    {
        return $this->mediaItemId !== null && $this->refusalReason === null;
    }
}
