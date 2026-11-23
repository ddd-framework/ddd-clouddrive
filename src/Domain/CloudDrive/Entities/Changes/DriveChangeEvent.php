<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Changes;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Repo\DB\Changes\DBDriveChangeEvent;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One change of a space, in the order they happened: the feed a working copy pulls (`changesSince`) and the activity
 * people read. The id is the cursor.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveChangeEvent::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveSpaceId', 'id'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveNodeId'])]
class DriveChangeEvent extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveChangesService::class;

    public const string EVENT_CREATED = 'CREATED';
    public const string EVENT_VERSIONED = 'VERSIONED';
    public const string EVENT_RELEASED = 'RELEASED';
    public const string EVENT_BLOCKED = 'BLOCKED';
    public const string EVENT_MOVED = 'MOVED';
    public const string EVENT_RENAMED = 'RENAMED';
    public const string EVENT_METADATA = 'METADATA';
    public const string EVENT_TRASHED = 'TRASHED';
    public const string EVENT_RESTORED = 'RESTORED';
    public const string EVENT_PURGED = 'PURGED';
    public const string EVENT_COPIED_IN = 'COPIED_IN';
    public const string EVENT_READ_BY_AGENT = 'READ_BY_AGENT';

    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveSpaceId = null;

    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveNodeId = null;

    #[NotNull]
    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $eventType = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveFileVersionId = null;

    /** @var string|null The node's path after the change (for people; a working copy reads the node) */
    #[Length(max: 2000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $path = null;

    /** @var string|null The path before a move or rename */
    #[Length(max: 2000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $previousPath = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $actorAccountId = null;

    /** @var string|null The working copy that made the change: its own pull skips it */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $workingCopyKey = null;
}
