<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Renditions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;

/**
 * Where the PDF rendition of an Office file stands for a reader: READY with a short-lived content URL, PENDING while
 * a worker converts it (ask again shortly), or FAILED with a short reason a person can read.
 */
class DriveOfficeRendition extends ValueObject
{
    public const string STATE_READY = 'READY';
    public const string STATE_PENDING = 'PENDING';
    public const string STATE_FAILED = 'FAILED';
    public const array STATES = [self::STATE_READY, self::STATE_PENDING, self::STATE_FAILED];

    /** @var string One of {@see STATES} */
    public string $state = self::STATE_PENDING;

    /** @var string|null FAILED only: why there is no rendition (plain words, never the converter's output) */
    public ?string $reason = null;

    /** @var DriveContentGrant|null READY only: the inline URL of the PDF */
    public ?DriveContentGrant $grant = null;

    public static function create(string $state, ?string $reason = null, ?DriveContentGrant $grant = null): static
    {
        $driveOfficeRendition = new static();
        $driveOfficeRendition->state = $state;
        $driveOfficeRendition->reason = $reason;
        $driveOfficeRendition->grant = $grant;
        return $driveOfficeRendition;
    }
}
