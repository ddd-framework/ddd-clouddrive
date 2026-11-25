<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** Content grants on the in-memory services of the transfer tests (no signed-in account, no agent) */
class TransferTestDriveContentGrantsService extends DriveContentGrantsService
{
    public function __construct(
        public TransferTestDriveSpacesService $testDriveSpacesService,
        public TransferTestDriveVersionsService $testDriveVersionsService,
        public TransferTestDriveBlobsService $testDriveBlobsService
    ) {
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->testDriveSpacesService;
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        return $this->testDriveVersionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return $this->testDriveBlobsService;
    }

    /** The stored renditions of the test's object store (nothing is ever converted here) */
    protected function getDriveOfficeRenditionsService(): DriveOfficeRenditionsService
    {
        return new RenditionTestDriveOfficeRenditionsService($this, $this->testDriveBlobsService, new TransferTestDriveNodesService());
    }

    protected function findCurrentAccountId(): ?int
    {
        return null;
    }

    protected function findOnBehalfOfAccountId(): ?int
    {
        return null;
    }
}
