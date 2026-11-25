<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\Installation\DriveFeatureTableConditionProviderService;

/** The drive table conditions with the drive switch set by the test */
class SwitchableDriveFeatureTableConditionProviderService extends DriveFeatureTableConditionProviderService
{
    public bool $driveEnabled = false;

    protected function isDriveEnabled(): bool
    {
        return $this->driveEnabled;
    }
}
