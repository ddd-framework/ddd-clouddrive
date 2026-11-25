<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;

/** Content grants of the rendition tests: the in-memory services of the transfer tests plus nodes and the rendition service of the test */
class RenditionTestDriveContentGrantsService extends TransferTestDriveContentGrantsService
{
    public ?DriveNodesService $testDriveNodesService = null;

    public ?DriveOfficeRenditionsService $testDriveOfficeRenditionsService = null;

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->testDriveNodesService ?? parent::getDriveNodesService();
    }

    protected function getDriveOfficeRenditionsService(): DriveOfficeRenditionsService
    {
        return $this->testDriveOfficeRenditionsService ?? parent::getDriveOfficeRenditionsService();
    }
}
