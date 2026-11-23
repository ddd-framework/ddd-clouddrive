<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Versions;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;

/**
 * Rights through the space: a row is readable when its space is readable, writable when its space is writable
 * ({@see DBDriveSpaceScopedEntity}).
 *
 * @method DriveFileVersion find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DriveFileVersion update(DefaultObject &$entity, int $depth = 1)
 */
class DBDriveFileVersion extends DBDriveSpaceScopedEntity
{
    public const string BASE_ENTITY_CLASS = DriveFileVersion::class;
    public const string BASE_ORM_MODEL = DBDriveFileVersionModel::class;
}
