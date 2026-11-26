<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use ReflectionClass;

/** The working copies of the import tests; every pull request is recorded */
class FileImportDriveWorkingCopiesService extends DriveWorkingCopiesService
{
    /** @var DriveWorkingCopy[] Working copies by key, the last one the newest */
    public array $driveWorkingCopiesByKey = [];

    /** @var string[] The keys of the working copies marked for a pull */
    public array $pullRequestedKeys = [];

    public function __construct()
    {
    }

    public function addWorkingCopy(int $id, string $workingCopyKey): DriveWorkingCopy
    {
        /** @var DriveWorkingCopy $driveWorkingCopy */
        $driveWorkingCopy = (new ReflectionClass(DriveWorkingCopy::class))->newInstanceWithoutConstructor();
        $driveWorkingCopy->id = $id;
        $driveWorkingCopy->workingCopyKey = $workingCopyKey;
        $this->driveWorkingCopiesByKey[$workingCopyKey] = $driveWorkingCopy;
        return $driveWorkingCopy;
    }

    public function findWorkingCopy(DriveSpace $driveSpace, string $workingCopyKey): ?DriveWorkingCopy
    {
        return $this->driveWorkingCopiesByKey[$workingCopyKey] ?? null;
    }

    public function findNewestWorkingCopy(DriveSpace $driveSpace): ?DriveWorkingCopy
    {
        return $this->driveWorkingCopiesByKey === [] ? null : end($this->driveWorkingCopiesByKey);
    }

    public function requestPull(DriveWorkingCopy $driveWorkingCopy): void
    {
        $this->pullRequestedKeys[] = (string)$driveWorkingCopy->workingCopyKey;
    }
}
