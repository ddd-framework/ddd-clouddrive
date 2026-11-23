<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Activities;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;

/**
 * Rights through the space ({@see DBDriveSpaceScopedEntity}).
 *
 * @method DriveActivityEvent find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DriveActivityEvent update(DefaultObject &$entity, int $depth = 1)
 */
class DBDriveActivityEvent extends DBDriveSpaceScopedEntity
{
    public const string BASE_ENTITY_CLASS = DriveActivityEvent::class;
    public const string BASE_ORM_MODEL = DBDriveActivityEventModel::class;
}
