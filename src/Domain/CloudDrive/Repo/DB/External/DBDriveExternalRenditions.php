<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\External;

use DDD\Domain\CloudDrive\Entities\External\DriveExternalRenditions;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DriveExternalRenditions|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveExternalRenditions extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveExternalRendition::class;
    public const string BASE_ENTITY_SET_CLASS = DriveExternalRenditions::class;
}
