<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Blobs;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlobs;

/**
 * @method DriveBlobs find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveBlobs extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveBlob::class;
    public const string BASE_ENTITY_SET_CLASS = DriveBlobs::class;
}
