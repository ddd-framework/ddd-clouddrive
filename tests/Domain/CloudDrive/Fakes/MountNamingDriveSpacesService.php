<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;

/** Spaces the caller may read, by id and by mount name, without a database */
class MountNamingDriveSpacesService extends DriveSpacesService
{
    /** @var DriveSpace[] Readable spaces by id */
    public array $readableDriveSpacesById = [];

    public function addReadableSpace(DriveSpace $driveSpace): void
    {
        $this->readableDriveSpacesById[(int)$driveSpace->id] = $driveSpace;
    }

    public function findSpaceWithRight(int $driveSpaceId, string $right): ?DriveSpace
    {
        return $this->readableDriveSpacesById[$driveSpaceId] ?? null;
    }

    public function findReadableSpaceByMountName(string $mountName): ?DriveSpace
    {
        foreach ($this->readableDriveSpacesById as $readableDriveSpace) {
            if ($readableDriveSpace->buildMountName() === $mountName) {
                return $readableDriveSpace;
            }
        }
        return null;
    }
}
