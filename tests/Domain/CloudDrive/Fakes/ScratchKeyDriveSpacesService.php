<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;

/** Scratch spaces of eval cases by their key, without a database */
class ScratchKeyDriveSpacesService extends DriveSpacesService
{
    /** @var DriveSpace[] Scratch spaces by scratch key */
    public array $scratchDriveSpacesByKey = [];

    public function findSpaceByScratchKey(string $scratchKey): ?DriveSpace
    {
        return $this->scratchDriveSpacesByKey[$scratchKey] ?? null;
    }
}
