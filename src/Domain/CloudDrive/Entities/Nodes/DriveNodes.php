<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNodes;
use DDD\Domain\CloudDrive\Services\DriveNodesService;

/**
 * @property DriveNode[] $elements
 * @method DriveNode|null getByUniqueKey(string $uniqueKey)
 * @method DriveNode[] getElements()
 * @method DriveNode|null first()
 * @method static DriveNodesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveNodes::class)]
class DriveNodes extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DriveNodesService::class;
}
