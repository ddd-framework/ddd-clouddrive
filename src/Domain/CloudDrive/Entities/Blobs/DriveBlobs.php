<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Blobs;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\Blobs\DBDriveBlobs;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;

/**
 * @property DriveBlob[] $elements
 * @method DriveBlob|null getByUniqueKey(string $uniqueKey)
 * @method DriveBlob[] getElements()
 * @method DriveBlob|null first()
 * @method static DriveBlobsService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveBlobs::class)]
class DriveBlobs extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveBlobsService::class;
}
