<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\External;

use DDD\Domain\CloudDrive\Repo\DB\External\DBDriveExternalSpaces;
use DDD\Domain\CloudDrive\Services\External\DriveExternalSpacesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see DriveExternalSpace}.
 *
 * @property DriveExternalSpace[] $elements
 * @method DriveExternalSpace getByUniqueKey(string $uniqueKey)
 * @method DriveExternalSpace[] getElements()
 * @method DriveExternalSpace|null first()
 * @method static DriveExternalSpacesService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveExternalSpaces::class, forceDBEntityModelCreation: true)]
class DriveExternalSpaces extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveExternalSpacesService::class;
}
