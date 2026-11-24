<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveMaintenanceService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** The maintenance of the dispatch tests: in-memory services and dispatch locks (null = a cache without locks) */
class DispatchTestDriveMaintenanceService extends DriveMaintenanceService
{
    /** @var string[]|null Held locks by key; null = the cache has no locks */
    public ?array $heldLocks = [];

    public function __construct(
        public DispatchRecordingDriveVersionsService $testDriveVersionsService,
        public DispatchTestDriveBlobsService $testDriveBlobsService,
        public TransferTestDriveNodesService $testDriveNodesService
    ) {
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        return $this->testDriveVersionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return $this->testDriveBlobsService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->testDriveNodesService;
    }

    protected function acquireIngestDispatchLock(int $driveFileVersionId, int $lockSeconds): string|false|null
    {
        if ($this->heldLocks === null) {
            return null;
        }
        $lockKey = self::INGEST_DISPATCH_LOCK_PREFIX . $driveFileVersionId;
        if (isset($this->heldLocks[$lockKey])) {
            return false;
        }
        return $this->heldLocks[$lockKey] = 'token-' . $driveFileVersionId;
    }

    protected function releaseIngestDispatchLock(int $driveFileVersionId, ?string $lockToken): void
    {
        unset($this->heldLocks[self::INGEST_DISPATCH_LOCK_PREFIX . $driveFileVersionId]);
    }
}
