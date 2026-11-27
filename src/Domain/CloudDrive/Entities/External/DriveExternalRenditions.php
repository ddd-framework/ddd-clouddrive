<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\External;

use DDD\Domain\CloudDrive\Repo\DB\External\DBDriveExternalRenditions;
use DDD\Domain\CloudDrive\Services\External\DriveExternalRenditionsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see DriveExternalRendition}.
 *
 * @property DriveExternalRendition[] $elements
 * @method DriveExternalRendition getByUniqueKey(string $uniqueKey)
 * @method DriveExternalRendition[] getElements()
 * @method DriveExternalRendition|null first()
 * @method static DriveExternalRenditionsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveExternalRenditions::class, forceDBEntityModelCreation: true)]
class DriveExternalRenditions extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveExternalRenditionsService::class;
}
