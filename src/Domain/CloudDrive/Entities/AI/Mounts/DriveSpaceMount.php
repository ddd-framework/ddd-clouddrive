<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\Mounts;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;

/**
 * One further space an agent space's sandbox mounts below `drivespaces/<mountName>/`, and how its tools may write
 * there. A SHARED space is never mounted READ_WRITE: every write asks the owner first.
 */
class DriveSpaceMount extends ValueObject
{
    public ?DriveSpace $driveSpace = null;

    /** @var string The folder below `drivespaces/`, e.g. `library` or `world-31` */
    public string $mountName = '';

    /** @var string One of AgentDriveMount::MODES */
    public string $mode = AgentDriveMount::MODE_READ_ONLY;

    /** @var string|null Who sees the space, for the consent question (e.g. "partner users of World 31") */
    public ?string $audienceDescription = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->mountName);
    }
}
