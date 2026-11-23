<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Blobs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The malware scan of one blob: CLEAN, INFECTED (blocked for good) or FAILED (the scanner could not run: missing,
 * no signatures; the blob waits in SCAN_FAILED and `app:drive:maintain` scans it again).
 */
class DriveScanResult extends ValueObject
{
    public const string OUTCOME_CLEAN = 'CLEAN';
    public const string OUTCOME_INFECTED = 'INFECTED';
    public const string OUTCOME_FAILED = 'FAILED';

    public string $outcome = self::OUTCOME_CLEAN;

    /** @var string|null The scanner's finding or error */
    public ?string $detail = null;

    public static function create(string $outcome, ?string $detail = null): static
    {
        $driveScanResult = new static();
        $driveScanResult->outcome = $outcome;
        $driveScanResult->detail = $detail !== null ? mb_substr($detail, 0, 1000) : null;
        return $driveScanResult;
    }
}
