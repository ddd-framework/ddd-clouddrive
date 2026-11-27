<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\Installation\DriveFeatureTableConditionProviderService;

/** The drive table conditions with the drive and AgentConnections switches set by the test */
class SwitchableDriveFeatureTableConditionProviderService extends DriveFeatureTableConditionProviderService
{
    public bool $driveEnabled = false;

    public bool $agentConnectionsEnabled = false;

    protected function isDriveEnabled(): bool
    {
        return $this->driveEnabled;
    }

    protected function areAgentConnectionsEnabled(): bool
    {
        return $this->agentConnectionsEnabled;
    }
}
