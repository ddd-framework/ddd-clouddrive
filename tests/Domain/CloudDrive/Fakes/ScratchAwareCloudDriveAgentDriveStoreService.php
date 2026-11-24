<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;

/** The agent drive store reading scratch spaces from a test service */
class ScratchAwareCloudDriveAgentDriveStoreService extends CloudDriveAgentDriveStoreService
{
    public function __construct(public ScratchKeyDriveSpacesService $scratchKeyDriveSpacesService)
    {
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->scratchKeyDriveSpacesService;
    }
}
