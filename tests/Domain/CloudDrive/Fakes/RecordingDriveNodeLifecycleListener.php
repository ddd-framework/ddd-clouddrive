<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveNodeLifecycleListener;
use RuntimeException;

/** Remembers the purged ids it was told; throws when asked to */
class RecordingDriveNodeLifecycleListener implements DriveNodeLifecycleListener
{
    /** @var int[] */
    public array $purgedNodeIds = [];

    public function __construct(public bool $throwsOnPurge = false)
    {
    }

    public function onNodesPurged(DriveSpace $driveSpace, array $driveNodeIds): void
    {
        if ($this->throwsOnPurge) {
            throw new RuntimeException('listener down');
        }
        array_push($this->purgedNodeIds, ...$driveNodeIds);
    }
}
