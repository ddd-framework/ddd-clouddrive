<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The released bytes of one space by what they hold. Each released blob counts once (a blob several versions share is
 * not counted twice), under the first class it belongs to: a current version of a live file, else a file in the trash
 * (any version of a trashed file), else an older version (or a blob no version references any more, until the sweep
 * removes it). {@see \DDD\Domain\CloudDrive\Services\DriveBlobsService::findSpaceUsages()}
 */
class DriveSpaceUsage extends ValueObject
{
    public int $driveSpaceId = 0;

    /** @var int Bytes of the current versions of live files */
    public int $currentVersionBytes = 0;

    /** @var int Bytes only older versions of live files (or nothing) still hold */
    public int $olderVersionBytes = 0;

    /** @var int Bytes of files in the trash */
    public int $trashedBytes = 0;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->driveSpaceId);
    }
}
