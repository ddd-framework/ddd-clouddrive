<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Imports;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the caller of an import knows and the drive cannot: whether the owner already said yes to this step, and
 * whether the target space is the caller's own drive.
 */
class DriveImportOptions extends ValueObject
{
    /** @var bool True when the owner consented to this copy (an agent's consent step, a person's own click) */
    public bool $hasOwnerConsent = false;

    /** @var bool True when the target space belongs to the caller alone (the own drive of an agent space) */
    public bool $isOwnDrive = false;

    /** @var string|null The working copy to mark for a pull besides the space's newest one */
    public ?string $workingCopyKey = null;

    /** @var float|null How long the import waits for the scan decision of what it wrote, in seconds; null = the default */
    public ?float $scanWaitSeconds = null;
}
