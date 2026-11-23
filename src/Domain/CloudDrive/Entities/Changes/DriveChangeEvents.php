<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Changes;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\Changes\DBDriveChangeEvents;
use DDD\Domain\CloudDrive\Services\DriveChangesService;

/**
 * @property DriveChangeEvent[] $elements
 * @method DriveChangeEvent|null getByUniqueKey(string $uniqueKey)
 * @method DriveChangeEvent[] getElements()
 * @method DriveChangeEvent|null first()
 * @method static DriveChangesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveChangeEvents::class)]
class DriveChangeEvents extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveChangesService::class;
}
