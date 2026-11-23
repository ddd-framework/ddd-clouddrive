<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;

/**
 * Where a pasted location points: the space (which may differ from the requested one) and the file or folder there.
 */
class DriveLocationResolution extends ValueObject
{
    public ?DriveSpace $driveSpace = null;

    public ?DriveNode $driveNode = null;

    /** @var string The node's path in its space (no leading slash; `` = the root) */
    public string $path = '';
}
