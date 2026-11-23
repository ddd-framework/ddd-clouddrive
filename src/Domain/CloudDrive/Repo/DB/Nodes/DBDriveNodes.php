<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Nodes;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;

/**
 * @method DriveNodes find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveNodes extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveNode::class;
    public const string BASE_ENTITY_SET_CLASS = DriveNodes::class;
}
