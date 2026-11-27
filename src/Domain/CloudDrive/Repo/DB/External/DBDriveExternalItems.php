<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\External;

use DDD\Domain\CloudDrive\Entities\External\DriveExternalItems;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DriveExternalItems|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveExternalItems extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveExternalItem::class;
    public const string BASE_ENTITY_SET_CLASS = DriveExternalItems::class;
}
