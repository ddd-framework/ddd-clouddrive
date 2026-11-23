<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Activities;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvents;

/**
 * @method DriveActivityEvents find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDriveActivityEvents extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBDriveActivityEvent::class;
    public const string BASE_ENTITY_SET_CLASS = DriveActivityEvents::class;
}
