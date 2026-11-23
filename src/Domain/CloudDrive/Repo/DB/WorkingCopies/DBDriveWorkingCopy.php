<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\WorkingCopies;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;

/**
 * Rights through the space: a row is readable when its space is readable, writable when its space is writable
 * ({@see DBDriveSpaceScopedEntity}).
 *
 * @method DriveWorkingCopy find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DriveWorkingCopy update(DefaultObject &$entity, int $depth = 1)
 */
class DBDriveWorkingCopy extends DBDriveSpaceScopedEntity
{
    public const string BASE_ENTITY_CLASS = DriveWorkingCopy::class;
    public const string BASE_ORM_MODEL = DBDriveWorkingCopyModel::class;
}
