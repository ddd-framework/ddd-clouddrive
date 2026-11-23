<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopyEntries;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;

/**
 * @property DriveWorkingCopyEntry[] $elements
 * @method DriveWorkingCopyEntry|null getByUniqueKey(string $uniqueKey)
 * @method DriveWorkingCopyEntry[] getElements()
 * @method DriveWorkingCopyEntry|null first()
 * @method static DriveWorkingCopiesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveWorkingCopyEntries::class)]
class DriveWorkingCopyEntries extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveWorkingCopiesService::class;
}
