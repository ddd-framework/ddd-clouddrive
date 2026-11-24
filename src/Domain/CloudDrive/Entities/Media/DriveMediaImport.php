<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Media;

use DDD\Domain\Base\Entities\ValueObject;

/** What the caller asks for when a drive file is taken into the media library */
class DriveMediaImport extends ValueObject
{
    /** @var string|null The media item's title; null: the file name */
    public ?string $title = null;

    /** @var string|null What the picture shows (the library's description) */
    public ?string $description = null;
}
