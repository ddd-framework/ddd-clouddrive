<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** The versions service with a spaces service of the test (no container, no database) */
class SpacesProvidingDriveVersionsService extends DriveVersionsService
{
    public function __construct(public DriveSpacesService $testDriveSpacesService)
    {
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->testDriveSpacesService;
    }
}
