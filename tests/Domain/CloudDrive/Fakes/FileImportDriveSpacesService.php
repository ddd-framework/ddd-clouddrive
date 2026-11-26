<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use ReflectionClass;

/** The spaces the caller of a test may read, and the ones it may write */
class FileImportDriveSpacesService extends DriveSpacesService
{
    /** @var DriveSpace[] Readable spaces by id */
    public array $readableSpacesById = [];

    /** @var int[] Ids of the spaces the caller may write */
    public array $writableSpaceIds = [];

    public function __construct()
    {
    }

    public function addSpace(int $driveSpaceId, string $title, string $audience = DriveSpace::AUDIENCE_INTERNAL, bool $isWritable = true): DriveSpace
    {
        /** @var DriveSpace $driveSpace */
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->id = $driveSpaceId;
        $driveSpace->title = $title;
        $driveSpace->audience = $audience;
        $driveSpace->spaceKind = DriveSpace::DEFAULT_SPACE_KIND;
        $driveSpace->maxFileSizeBytes = 1048576;
        $this->readableSpacesById[$driveSpaceId] = $driveSpace;
        if ($isWritable) {
            $this->writableSpaceIds[] = $driveSpaceId;
        }
        return $driveSpace;
    }

    public function findSpaceWithRight(int $driveSpaceId, string $right): ?DriveSpace
    {
        $driveSpace = $this->readableSpacesById[$driveSpaceId] ?? null;
        if ($driveSpace === null || ($right !== DBDriveSpace::RIGHT_READ && !in_array($driveSpaceId, $this->writableSpaceIds, true))) {
            return null;
        }
        return $driveSpace;
    }

    public function requireSpaceWithRight(int $driveSpaceId, string $right = DBDriveSpace::RIGHT_READ): DriveSpace
    {
        if (!isset($this->readableSpacesById[$driveSpaceId])) {
            throw new NotFoundException(sprintf('Drive space %d not found.', $driveSpaceId));
        }
        $driveSpace = $this->findSpaceWithRight($driveSpaceId, $right);
        if ($driveSpace === null) {
            throw new ForbiddenException(sprintf('No %s right on drive space %d.', strtolower($right), $driveSpaceId));
        }
        return $driveSpace;
    }
}
