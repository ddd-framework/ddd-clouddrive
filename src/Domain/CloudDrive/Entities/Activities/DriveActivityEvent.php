<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Activities;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Repo\DB\Activities\DBDriveActivityEvent;
use DDD\Domain\CloudDrive\Services\DriveActivityEventsService;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * What happened to a file beside its changes ({@see \DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent}), in a
 * table of its own so the change feed never depends on it:
 *
 * - an access: a person opened a preview (`VIEWED`) or downloaded the original (`DOWNLOADED`), recorded when the
 *   content URL was granted (the bytes go from the store directly);
 * - a host event: a type the application records itself (`LINKED`, `IMPORTED_TO_MEDIA_LIBRARY` …) with a detail;
 * - the context of a change (`driveChangeEventId` set): who the actor acted for and as which audience.
 *
 * Without the table (code deployed before the schema) nothing is recorded and the history shows the changes only.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveActivityEvent::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveNodeId', 'id'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveChangeEventId'])]
class DriveActivityEvent extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveActivityEventsService::class;

    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveSpaceId = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveNodeId = null;

    /** @var int|null The change this row adds context to; null for an access or a host event */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveChangeEventId = null;

    /** @var string|null `VIEWED`, `DOWNLOADED`, a host type, or the type of the change it adds context to */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $eventType = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveFileVersionId = null;

    /** @var int|null Who acted; null = the system */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $actorAccountId = null;

    /** @var int|null The account the actor acted for (the staff or the agent for a partner or the space's owner) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $onBehalfOfAccountId = null;

    /** @var string|null The audience the actor acted as: INTERNAL or SHARED */
    #[Length(max: 16)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $audience = null;

    /** @var string|null The content variant of an access: INLINE (a preview) or DOWNLOAD (the original) */
    #[Length(max: 16)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $contentVariant = null;

    /** @var string|null A short human detail of a host event */
    #[Length(max: 500)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $detail = null;

    /**
     * @var string|null The public IP of the person's client of an access (IPv4 or IPv6, Symfony's
     *      `Request::getClientIp()` under the host's trusted proxies; never a raw forwarding header); null for agent
     *      reads, the console and rows written before the column existed. Shown to the INTERNAL audience only.
     */
    #[Length(max: 45)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $clientIp = null;
}
