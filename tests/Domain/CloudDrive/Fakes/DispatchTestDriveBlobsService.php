<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlobs;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;

/** Blobs of the maintenance dispatch tests: the failed scans are given */
class DispatchTestDriveBlobsService extends DriveBlobsService
{
    public DriveBlobs $blobsWithFailedScan;

    public function __construct()
    {
        $this->blobsWithFailedScan = new DriveBlobs();
    }

    public function findBlobsWithFailedScan(int $limit = 100): DriveBlobs
    {
        return $this->blobsWithFailedScan;
    }
}
