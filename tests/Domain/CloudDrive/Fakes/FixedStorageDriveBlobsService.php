<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;

/** The blob service answering the object store of the test */
class FixedStorageDriveBlobsService extends DriveBlobsService
{
    public function __construct(public ObjectStorage $testObjectStorage)
    {
    }

    public function getObjectStorage(): ObjectStorage
    {
        return $this->testObjectStorage;
    }
}
