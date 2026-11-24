<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentDelivery;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentToken;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** The content route's service on the in-memory services of the transfer tests; records accesses instead of storing them */
class TransferTestDriveContentStreamsService extends \DDD\Domain\CloudDrive\Services\DriveContentStreamsService
{
    /** @var string[] Purposes of the recorded accesses */
    public array $recordedAccessPurposes = [];

    public function __construct(
        public TransferTestDriveContentGrantsService $testDriveContentGrantsService,
        public TransferTestDriveNodesService $testDriveNodesService
    ) {
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        return $this->testDriveContentGrantsService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->testDriveContentGrantsService->testDriveSpacesService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->testDriveNodesService;
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        return $this->testDriveContentGrantsService->testDriveVersionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return $this->testDriveContentGrantsService->testDriveBlobsService;
    }

    protected function enterAccountOfToken(DriveContentToken $driveContentToken): void
    {
    }

    protected function recordAccess(DriveNode $driveNode, DriveContentDelivery $driveContentDelivery, DriveContentToken $driveContentToken, string $audience): void
    {
        $this->recordedAccessPurposes[] = $driveContentDelivery->purpose;
    }
}
