<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\Mounts;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;

/** A node a tool argument names, with the mount it was reached through */
class DriveMountedNode extends ValueObject
{
    public ?AgentDriveMount $agentDriveMount = null;

    public ?DriveNode $driveNode = null;
}
