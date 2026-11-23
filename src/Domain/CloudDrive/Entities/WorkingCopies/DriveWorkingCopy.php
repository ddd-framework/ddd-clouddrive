<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The working copy of a space in one sandbox (e.g. an agent space's workspace): the change cursor it has pulled up
 * to and, through its entries, the saved but uncommitted changes. One per (space, key).
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveWorkingCopy::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: ['driveSpaceId', 'workingCopyKey'])]
class DriveWorkingCopy extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveWorkingCopiesService::class;

    #[NotNull]
    public ?int $driveSpaceId = null;

    #[LazyLoad(addAsParent: true)]
    public ?DriveSpace $driveSpace;

    /** @var string The sandbox's key, e.g. the workspace key of an agent space */
    #[NotNull]
    #[Length(max: 128)]
    public ?string $workingCopyKey = null;

    /** @var int The newest change event the working copy has applied */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $changeCursor = 0;
}
