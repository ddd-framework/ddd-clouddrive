<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\WorkingCopies;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopies;

/**
 * @method DriveWorkingCopies find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveWorkingCopies extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveWorkingCopy::class;
    public const string BASE_ENTITY_SET_CLASS = DriveWorkingCopies::class;
}
