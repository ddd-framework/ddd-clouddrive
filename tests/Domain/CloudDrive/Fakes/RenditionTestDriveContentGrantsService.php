<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\CloudDrive\Services\DriveSvgSanitizersService;

/** Content grants of the rendition tests: the in-memory services of the transfer tests plus nodes and the rendition service of the test */
class RenditionTestDriveContentGrantsService extends TransferTestDriveContentGrantsService
{
    public ?DriveNodesService $testDriveNodesService = null;

    public ?DriveOfficeRenditionsService $testDriveOfficeRenditionsService = null;

    /** @var DriveSvgSanitizersService|null The host's sanitisers; none = a host without one */
    public ?DriveSvgSanitizersService $testDriveSvgSanitizersService = null;

    protected function getDriveSvgSanitizersService(): DriveSvgSanitizersService
    {
        return $this->testDriveSvgSanitizersService ?? new DriveSvgSanitizersService();
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->testDriveNodesService ?? parent::getDriveNodesService();
    }

    protected function getDriveOfficeRenditionsService(): DriveOfficeRenditionsService
    {
        return $this->testDriveOfficeRenditionsService ?? parent::getDriveOfficeRenditionsService();
    }
}
