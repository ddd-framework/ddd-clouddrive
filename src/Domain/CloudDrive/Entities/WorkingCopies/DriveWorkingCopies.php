<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopies;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;

/**
 * @property DriveWorkingCopy[] $elements
 * @method DriveWorkingCopy|null getByUniqueKey(string $uniqueKey)
 * @method DriveWorkingCopy[] getElements()
 * @method DriveWorkingCopy|null first()
 * @method static DriveWorkingCopiesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveWorkingCopies::class)]
class DriveWorkingCopies extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveWorkingCopiesService::class;
}
