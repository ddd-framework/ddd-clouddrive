<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\Agents\CloudDriveAgentDriveStoreService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;

/** The agent drive store reading nodes and the link template from a test service */
class OpenLinkCloudDriveAgentDriveStoreService extends CloudDriveAgentDriveStoreService
{
    public function __construct(public LinkTemplateDriveNodesService $linkTemplateDriveNodesService)
    {
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->linkTemplateDriveNodesService;
    }
}
