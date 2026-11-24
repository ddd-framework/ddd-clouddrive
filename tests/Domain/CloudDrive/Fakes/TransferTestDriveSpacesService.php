<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use ReflectionClass;

/** Rights of the transfer tests: every right until the test revokes the read right */
class TransferTestDriveSpacesService extends DriveSpacesService
{
    public bool $isReadRevoked = false;

    public function requireSpaceWithRight(int $driveSpaceId, string $right = DBDriveSpace::RIGHT_READ): DriveSpace
    {
        if ($this->isReadRevoked) {
            throw new ForbiddenException(sprintf('No %s right on drive space %d.', strtolower($right), $driveSpaceId));
        }
        /** @var DriveSpace $driveSpace */
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->id = $driveSpaceId;
        return $driveSpace;
    }
}
