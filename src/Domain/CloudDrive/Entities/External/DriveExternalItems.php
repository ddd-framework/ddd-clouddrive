<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\External;

use DDD\Domain\CloudDrive\Repo\DB\External\DBDriveExternalItems;
use DDD\Domain\CloudDrive\Services\External\DriveExternalItemsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see DriveExternalItem}.
 *
 * @property DriveExternalItem[] $elements
 * @method DriveExternalItem getByUniqueKey(string $uniqueKey)
 * @method DriveExternalItem[] getElements()
 * @method DriveExternalItem|null first()
 * @method static DriveExternalItemsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveExternalItems::class, forceDBEntityModelCreation: true)]
class DriveExternalItems extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveExternalItemsService::class;
}
