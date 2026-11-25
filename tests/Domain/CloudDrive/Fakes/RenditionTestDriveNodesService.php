<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;

/** Nodes of the rendition tests, in memory; a node's path is its name, and there is no link template */
class RenditionTestDriveNodesService extends TransferTestDriveNodesService
{
    public function buildPath(DriveNode $driveNode): string
    {
        return (string)$driveNode->name;
    }

    public function getAdminLinkTemplate(): ?string
    {
        return null;
    }
}
