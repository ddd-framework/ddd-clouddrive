<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces\AgentSpaceDriveSpace;

/**
 * The rows are read and written with the rights switched off: the mount resolver decides which space belongs to an
 * agent space; every drive tool still checks the caller's right on the space it touches.
 *
 * @method AgentSpaceDriveSpace find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentSpaceDriveSpace update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentSpaceDriveSpaceModel $ormInstance
 */
class DBAgentSpaceDriveSpace extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentSpaceDriveSpace::class;
    public const string BASE_ORM_MODEL = DBAgentSpaceDriveSpaceModel::class;
}
