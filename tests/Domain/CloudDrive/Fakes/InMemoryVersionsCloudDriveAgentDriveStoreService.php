<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** The agent drive store reading versions from a test service */
class InMemoryVersionsCloudDriveAgentDriveStoreService extends CloudDriveAgentDriveStoreService
{
    public function __construct(public InMemoryDriveVersionsService $inMemoryDriveVersionsService)
    {
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        return $this->inMemoryDriveVersionsService;
    }
}
