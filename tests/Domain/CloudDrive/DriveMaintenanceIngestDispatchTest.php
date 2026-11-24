<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersions;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\DispatchRecordingDriveVersionsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\DispatchTestDriveBlobsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\DispatchTestDriveMaintenanceService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveNodesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The maintenance never scans in its own process: failed scans and lost ingests go to the `drive_ingest` worker, one
 * message per version, not twice while one is queued, and a failing dispatch does not stop the batch.
 */
class DriveMaintenanceIngestDispatchTest extends TestCase
{
    protected DispatchRecordingDriveVersionsService $driveVersionsService;

    protected DispatchTestDriveBlobsService $driveBlobsService;

    protected TransferTestDriveNodesService $driveNodesService;

    protected DispatchTestDriveMaintenanceService $driveMaintenanceService;

    protected function setUp(): void
    {
        $this->driveVersionsService = new DispatchRecordingDriveVersionsService();
        $this->driveBlobsService = new DispatchTestDriveBlobsService();
        $this->driveNodesService = new TransferTestDriveNodesService();
        $this->driveMaintenanceService = new DispatchTestDriveMaintenanceService($this->driveVersionsService, $this->driveBlobsService, $this->driveNodesService);
    }

    protected function createVersion(int $versionId, int $nodeId, int $blobId): DriveFileVersion
    {
        $driveFileVersion = (new ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $driveFileVersion->id = $versionId;
        $driveFileVersion->driveNodeId = $nodeId;
        $driveFileVersion->driveBlobId = $blobId;
        return $driveFileVersion;
    }

    protected function createVersions(DriveFileVersion ...$driveFileVersions): DriveFileVersions
    {
        $versions = new DriveFileVersions();
        foreach ($driveFileVersions as $driveFileVersion) {
            $versions->add($driveFileVersion);
        }
        return $versions;
    }

    public function testEachVersionIsDispatchedOnceAndNeverIngestedInProcess(): void
    {
        $systemNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $systemNode->id = 20;
        $systemNode->isSystem = true;
        $this->driveNodesService->nodesById[20] = $systemNode;
        $versions = $this->createVersions($this->createVersion(1, 10, 100), $this->createVersion(2, 20, 200));

        $firstRunLines = $this->driveMaintenanceService->dispatchIngestOfVersions($versions, 'ingest again', false);
        self::assertSame(['1:scanned:async', '2:unscanned:async'], $this->driveVersionsService->ingestCalls, 'one message per version, a system file unscanned');
        self::assertStringContainsString('dispatched 2 ingest message(s) to drive_ingest', (string)end($firstRunLines));

        $secondRunLines = $this->driveMaintenanceService->dispatchIngestOfVersions($versions, 'ingest again', false);
        self::assertCount(2, $this->driveVersionsService->ingestCalls, 'a queued version is not dispatched again');
        self::assertStringContainsString('dispatched 0 ingest message(s) to drive_ingest (ingest again), 2 already queued', (string)end($secondRunLines));
    }

    public function testAFailingDispatchIsReportedReleasesItsLockAndTheBatchGoesOn(): void
    {
        $this->driveVersionsService->failingVersionIds = [1];
        $versions = $this->createVersions($this->createVersion(1, 10, 100), $this->createVersion(2, 11, 101));

        $resultLines = $this->driveMaintenanceService->dispatchIngestOfVersions($versions, 'scan again', false);
        self::assertSame(['2:scanned:async'], $this->driveVersionsService->ingestCalls);
        self::assertStringContainsString('version 1 could not be dispatched: broker unreachable', implode("\n", $resultLines));
        self::assertStringContainsString('1 failed', (string)end($resultLines));

        $this->driveVersionsService->failingVersionIds = [];
        $this->driveMaintenanceService->dispatchIngestOfVersions($versions, 'scan again', false);
        self::assertSame(['2:scanned:async', '1:scanned:async'], $this->driveVersionsService->ingestCalls, 'the failed one is dispatched on the next run');
    }

    public function testACacheWithoutLocksDispatchesEveryRunAndADryRunDispatchesNothing(): void
    {
        $versions = $this->createVersions($this->createVersion(1, 10, 100));
        $dryRunLines = $this->driveMaintenanceService->dispatchIngestOfVersions($versions, 'ingest again', true);
        self::assertSame([], $this->driveVersionsService->ingestCalls);
        self::assertStringContainsString('would dispatch 1 ingest message(s)', (string)end($dryRunLines));

        $this->driveMaintenanceService->heldLocks = null;
        $this->driveMaintenanceService->dispatchIngestOfVersions($versions, 'ingest again', false);
        $this->driveMaintenanceService->dispatchIngestOfVersions($versions, 'ingest again', false);
        self::assertCount(2, $this->driveVersionsService->ingestCalls);
    }

    public function testFailedScansAreDispatchedPerVersionOfTheirBlob(): void
    {
        foreach ([300, 301] as $blobId) {
            $failedBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
            $failedBlob->id = $blobId;
            $failedBlob->driveSpaceId = 1;
            $failedBlob->state = DriveBlob::STATE_SCAN_FAILED;
            $this->driveBlobsService->blobsWithFailedScan->add($failedBlob);
        }
        $this->driveVersionsService->versionsByBlobId[300] = $this->createVersions($this->createVersion(5, 50, 300), $this->createVersion(6, 51, 300));

        $resultLines = $this->driveMaintenanceService->rescanFailedScans(false);
        self::assertSame(['5:scanned:async', '6:scanned:async'], $this->driveVersionsService->ingestCalls);
        self::assertStringContainsString('blob 301 of space 1 failed its scan and has no version', implode("\n", $resultLines));
    }
}
