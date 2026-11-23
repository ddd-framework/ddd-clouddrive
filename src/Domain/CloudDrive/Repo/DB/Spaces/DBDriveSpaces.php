<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Spaces;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;

/**
 * @method DriveSpaces find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveSpaces extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveSpace::class;
    public const string BASE_ENTITY_SET_CLASS = DriveSpaces::class;
}
