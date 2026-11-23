<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The decision on one conflicted path: MINE (commit on top of the newer version), THEIRS (drop the local change) or
 * BOTH (mine under another name, `bothName`).
 */
class DriveCommitResolution extends ValueObject
{
    public const string RESOLUTION_MINE = 'mine';
    public const string RESOLUTION_THEIRS = 'theirs';
    public const string RESOLUTION_BOTH = 'both';
    public const array RESOLUTIONS = [self::RESOLUTION_MINE, self::RESOLUTION_THEIRS, self::RESOLUTION_BOTH];

    public string $path = '';

    public string $resolution = self::RESOLUTION_MINE;

    /** @var string|null BOTH: the file name of mine in the same folder */
    public ?string $bothName = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->path);
    }
}
