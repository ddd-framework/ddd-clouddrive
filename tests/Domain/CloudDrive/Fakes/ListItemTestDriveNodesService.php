<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlobs;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;

/**
 * The node service building list items from versions and blobs the test holds: no working copy has changes, nobody
 * acted for another account.
 */
class ListItemTestDriveNodesService extends DriveNodesService
{
    /** @var DriveBlob[] Blobs by id */
    public array $blobsById = [];

    public function __construct(
        public DriveSpacesService $testDriveSpacesService,
        public InMemoryDriveVersionsService $testDriveVersionsService,
        public DriveOfficeRenditionsService $testDriveOfficeRenditionsService
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

    protected function getDriveOfficeRenditionsService(): DriveOfficeRenditionsService
    {
        return $this->testDriveOfficeRenditionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return new class ($this->blobsById) extends DriveBlobsService {
            /** @param DriveBlob[] $blobsById */
            public function __construct(public array $blobsById)
            {
            }

            public function findBlobsByIds(array $driveBlobIds): DriveBlobs
            {
                $driveBlobs = new DriveBlobs();
                foreach ($driveBlobIds as $driveBlobId) {
                    if (isset($this->blobsById[(int)$driveBlobId])) {
                        $driveBlobs->add($this->blobsById[(int)$driveBlobId]);
                    }
                }
                return $driveBlobs;
            }
        };
    }

    protected function getDriveWorkingCopiesService(): DriveWorkingCopiesService
    {
        return new class extends DriveWorkingCopiesService {
            public function __construct()
            {
            }

            public function findNodeIdsWithStagedChanges(int $driveSpaceId, array $driveNodeIds): array
            {
                return [];
            }
        };
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        return new class extends DriveChangesService {
            public function __construct()
            {
            }

            public function findCreationOnBehalfOfAccountIds(array $driveNodeIds): array
            {
                return [];
            }
        };
    }
}
