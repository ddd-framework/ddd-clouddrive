<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Activities;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\Activities\DBDriveActivityEvents;
use DDD\Domain\CloudDrive\Services\DriveActivityEventsService;

/**
 * @property DriveActivityEvent[] $elements
 * @method DriveActivityEvent|null getByUniqueKey(string $uniqueKey)
 * @method DriveActivityEvent[] getElements()
 * @method DriveActivityEvent|null first()
 * @method static DriveActivityEventsService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveActivityEvents::class)]
class DriveActivityEvents extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveActivityEventsService::class;
}
