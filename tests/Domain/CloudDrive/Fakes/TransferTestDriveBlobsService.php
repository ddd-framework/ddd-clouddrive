<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;

/** The blob service of the transfer tests: blobs in memory, the store of the test */
class TransferTestDriveBlobsService extends DriveBlobsService
{
    /** @var DriveBlob[] Blobs by id */
    public array $blobsById = [];

    public function __construct(public ObjectStorage $testObjectStorage, public DriveSpacesService $testDriveSpacesService)
    {
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->blobsById[(int)$entityId] ?? null;
    }

    public function getObjectStorage(): ObjectStorage
    {
        return $this->testObjectStorage;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->testDriveSpacesService;
    }
}
