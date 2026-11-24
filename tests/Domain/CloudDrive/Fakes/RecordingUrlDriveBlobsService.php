<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;

/** The blob service on a recording store (no object storage configuration needed) */
class RecordingUrlDriveBlobsService extends DriveBlobsService
{
    public function __construct(public RecordingUrlObjectStorage $recordingUrlObjectStorage)
    {
    }

    public function getObjectStorage(): ObjectStorage
    {
        return $this->recordingUrlObjectStorage;
    }
}
