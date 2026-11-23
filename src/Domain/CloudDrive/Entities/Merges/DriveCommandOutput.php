<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Merges;

use DDD\Domain\Base\Entities\ValueObject;

/** The raw standard output and the exit code of one command run */
class DriveCommandOutput extends ValueObject
{
    public string $standardOutput = '';

    public int $exitCode = 0;
}
