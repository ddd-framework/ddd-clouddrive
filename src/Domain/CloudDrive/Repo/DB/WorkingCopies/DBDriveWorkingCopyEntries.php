<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\WorkingCopies;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntries;

/**
 * @method DriveWorkingCopyEntries find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveWorkingCopyEntries extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveWorkingCopyEntry::class;
    public const string BASE_ENTITY_SET_CLASS = DriveWorkingCopyEntries::class;
}
