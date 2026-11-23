<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Blobs;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;

/**
 * Rights through the space: a row is readable when its space is readable, writable when its space is writable
 * ({@see DBDriveSpaceScopedEntity}).
 *
 * @method DriveBlob find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DriveBlob update(DefaultObject &$entity, int $depth = 1)
 */
class DBDriveBlob extends DBDriveSpaceScopedEntity
{
    public const string BASE_ENTITY_CLASS = DriveBlob::class;
    public const string BASE_ORM_MODEL = DBDriveBlobModel::class;
}
