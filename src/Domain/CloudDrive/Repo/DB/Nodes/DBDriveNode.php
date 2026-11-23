<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Nodes;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;

/**
 * Rights through the space: a row is readable when its space is readable, writable when its space is writable
 * ({@see DBDriveSpaceScopedEntity}).
 *
 * @method DriveNode find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DriveNode update(DefaultObject &$entity, int $depth = 1)
 */
class DBDriveNode extends DBDriveSpaceScopedEntity
{
    public const string BASE_ENTITY_CLASS = DriveNode::class;
    public const string BASE_ORM_MODEL = DBDriveNodeModel::class;
}
