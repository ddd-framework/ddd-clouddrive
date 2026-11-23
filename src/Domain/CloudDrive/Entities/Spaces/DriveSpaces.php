<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaces;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;

/**
 * @property DriveSpace[] $elements
 * @method DriveSpace|null getByUniqueKey(string $uniqueKey)
 * @method DriveSpace[] getElements()
 * @method DriveSpace|null first()
 * @method static DriveSpacesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveSpaces::class)]
class DriveSpaces extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveSpacesService::class;
}
