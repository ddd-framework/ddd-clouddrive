<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\External;

use DDD\Domain\CloudDrive\Entities\External\DriveExternalSpaces;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DriveExternalSpaces|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveExternalSpaces extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveExternalSpace::class;
    public const string BASE_ENTITY_SET_CLASS = DriveExternalSpaces::class;
}
