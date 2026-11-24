<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveNodesService;

/** The node service answering the node's name as its path below the space root */
class FixedPathDriveNodesService extends DriveNodesService
{
    public function __construct()
    {
    }

    public function buildPath(DriveNode $driveNode): string
    {
        return (string)$driveNode->name;
    }
}
