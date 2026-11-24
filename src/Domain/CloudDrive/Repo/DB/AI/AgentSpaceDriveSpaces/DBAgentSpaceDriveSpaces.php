<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces\AgentSpaceDriveSpaces;

/**
 * @method AgentSpaceDriveSpaces find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentSpaceDriveSpaces extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentSpaceDriveSpace::class;
    public const string BASE_ENTITY_SET_CLASS = AgentSpaceDriveSpaces::class;
}
