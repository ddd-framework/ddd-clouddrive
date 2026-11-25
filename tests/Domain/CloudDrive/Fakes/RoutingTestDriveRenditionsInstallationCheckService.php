<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\CloudDrive\Services\Installation\DriveRenditionsInstallationCheckService;

/** The rendition installation check on the rendition service of a test (settings and routing set by the test) */
class RoutingTestDriveRenditionsInstallationCheckService extends DriveRenditionsInstallationCheckService
{
    public function __construct(public DriveOfficeRenditionsService $testDriveOfficeRenditionsService)
    {
    }

    protected function getDriveOfficeRenditionsService(): DriveOfficeRenditionsService
    {
        return $this->testDriveOfficeRenditionsService;
    }
}
