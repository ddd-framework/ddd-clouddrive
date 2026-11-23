<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Versions;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersions;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/**
 * @property DriveFileVersion[] $elements
 * @method DriveFileVersion|null getByUniqueKey(string $uniqueKey)
 * @method DriveFileVersion[] getElements()
 * @method DriveFileVersion|null first()
 * @method static DriveVersionsService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveFileVersions::class)]
class DriveFileVersions extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveVersionsService::class;
}
