<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent;
use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvents;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Activities\DBDriveActivityEvent;
use DDD\Domain\CloudDrive\Repo\DB\Activities\DBDriveActivityEvents;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The activity of files beside their changes ({@see DriveActivityEvent}): accesses (`VIEWED`, `DOWNLOADED`), host
 * events, and the context of a change (on behalf of whom, as which audience). Writes never fail the caller: without
 * the table (checked once per process) or on a database error nothing is recorded and a warning is logged.
 *
 * @method DriveActivityEvent|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DBDriveActivityEvent getEntityRepoClassInstance()
 * @method DBDriveActivityEvents getEntitySetRepoClassInstance()
 */
class DriveActivityEventsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DriveActivityEvent::class;

    /** @var string A host event type: upper case, digits and underscores, 2 to 64 characters */
    public const string HOST_EVENT_TYPE_PATTERN = '/^[A-Z][A-Z0-9_]{1,63}$/';

    /** @var int Default of `CloudDrive.settings.accessEventDedupSeconds` */
    public const int DEFAULT_ACCESS_EVENT_DEDUP_SECONDS = 600;

    /** @var bool[] Whether the activity table exists, per table name and process */
    protected static array $isTableAvailableByTableName = [];

    /**
     * Records that a person opened a preview (INLINE: `VIEWED`) or downloaded the original (DOWNLOAD: `DOWNLOADED`) of
     * a file; a thumbnail is no access. The same actor, file, version and type within `accessEventDedupSeconds` is
     * recorded once. Answers the recorded event, null when nothing was recorded.
     */
    public function recordContentAccess(DriveNode $driveNode, DriveContentGrant $driveContentGrant, string $audience, ?int $onBehalfOfAccountId = null): ?DriveActivityEvent
    {
        $eventType = match ($driveContentGrant->purpose) {
            DriveContentGrant::PURPOSE_INLINE => DriveChangeEvent::EVENT_VIEWED,
            DriveContentGrant::PURPOSE_DOWNLOAD => DriveChangeEvent::EVENT_DOWNLOADED,
            default => null,
        };
        if ($eventType === null || !$this->isActivityTableAvailable()) {
            return null;
        }
        $actorAccountId = $this->findActingAccountId();
        try {
            if ($this->hasRecentAccess($driveNode, $driveContentGrant->driveFileVersionId, $eventType, $actorAccountId)) {
                return null;
            }
        } catch (Throwable $throwable) {
            $this->logRecordingFailure('access dedup', $driveNode, $throwable);
            return null;
        }
        $driveActivityEvent = $this->buildActivityEvent($driveNode, $eventType, $actorAccountId, $onBehalfOfAccountId, $audience);
        $driveActivityEvent->driveFileVersionId = $driveContentGrant->driveFileVersionId;
        $driveActivityEvent->contentVariant = $driveContentGrant->purpose;
        return $this->storeActivityEvent($driveActivityEvent, $driveNode);
    }

    /**
     * Records an event of the application ({@see DriveChangesService::recordHostEvent()}).
     *
     * @throws BadRequestException a type that is no host type (pattern, or a type of the drive itself)
     */
    public function recordHostEvent(DriveNode $driveNode, string $eventType, ?string $detail, ?int $actorAccountId, ?int $onBehalfOfAccountId, string $audience): ?DriveActivityEvent
    {
        if (preg_match(static::HOST_EVENT_TYPE_PATTERN, $eventType) !== 1 || in_array($eventType, DriveChangeEvent::DRIVE_EVENT_TYPES, true)) {
            throw new BadRequestException(sprintf('"%s" is no host event type (upper case, digits and _, 2 to 64 characters, none of the drive\'s own types).', $eventType));
        }
        if (!$this->isActivityTableAvailable()) {
            return null;
        }
        $driveActivityEvent = $this->buildActivityEvent($driveNode, $eventType, $actorAccountId, $onBehalfOfAccountId, $audience);
        $driveActivityEvent->detail = $detail !== null && trim($detail) !== '' ? mb_substr(trim($detail), 0, 500) : null;
        return $this->storeActivityEvent($driveActivityEvent, $driveNode);
    }

    /** Records who a change's actor acted for and as which audience (only when there is something to record) */
    public function recordChangeContext(DriveChangeEvent $driveChangeEvent, ?int $onBehalfOfAccountId, string $audience): ?DriveActivityEvent
    {
        if ($onBehalfOfAccountId === null && $audience === DriveSpace::AUDIENCE_INTERNAL) {
            return null;
        }
        if (!isset($driveChangeEvent->id) || !$this->isActivityTableAvailable()) {
            return null;
        }
        $driveActivityEvent = DriveActivityEvent::newInstance();
        $driveActivityEvent->driveSpaceId = (int)$driveChangeEvent->driveSpaceId;
        $driveActivityEvent->driveNodeId = $driveChangeEvent->driveNodeId;
        $driveActivityEvent->driveChangeEventId = (int)$driveChangeEvent->id;
        $driveActivityEvent->eventType = (string)$driveChangeEvent->eventType;
        $driveActivityEvent->driveFileVersionId = $driveChangeEvent->driveFileVersionId;
        $driveActivityEvent->actorAccountId = $driveChangeEvent->actorAccountId;
        $driveActivityEvent->onBehalfOfAccountId = $onBehalfOfAccountId;
        $driveActivityEvent->audience = $audience;
        return $this->storeActivityEvent($driveActivityEvent, null);
    }

    /**
     * The newest accesses and host events of a node, newest first (without the context rows of changes); none without
     * the table.
     *
     * @param string[]|null $eventTypes only these types
     */
    public function findActivityEventsOfNode(DriveNode $driveNode, int $limit, ?array $eventTypes = null): DriveActivityEvents
    {
        if (!$this->isActivityTableAvailable() || $eventTypes === []) {
            return new DriveActivityEvents();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.driveNodeId = :driveNodeId")
            ->andWhere("{$alias}.driveChangeEventId IS NULL")
            ->setParameter('driveSpaceId', (int)$driveNode->driveSpaceId)
            ->setParameter('driveNodeId', (int)$driveNode->id)
            ->orderBy("{$alias}.id", 'DESC')
            ->setMaxResults(max(1, min(DriveChangesService::MAX_NODE_HISTORY_LIMIT, $limit)));
        if ($eventTypes !== null) {
            $queryBuilder->andWhere("{$alias}.eventType IN (:eventTypes)")->setParameter('eventTypes', array_values($eventTypes));
        }
        try {
            $driveActivityEvents = $repoClass->find($queryBuilder, false);
        } catch (Throwable $throwable) {
            $this->logRecordingFailure('read', $driveNode, $throwable);
            return new DriveActivityEvents();
        }
        return $driveActivityEvents instanceof DriveActivityEvents ? $driveActivityEvents : new DriveActivityEvents();
    }

    /**
     * The context rows of changes, by change id (none without the table).
     *
     * @param int[] $driveChangeEventIds
     */
    public function findContextsOfChangeEvents(array $driveChangeEventIds): DriveActivityEvents
    {
        $driveChangeEventIds = array_values(array_unique(array_filter(array_map('intval', $driveChangeEventIds))));
        if ($driveChangeEventIds === [] || !$this->isActivityTableAvailable()) {
            return new DriveActivityEvents();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveChangeEventId IN (:driveChangeEventIds)")->setParameter('driveChangeEventIds', $driveChangeEventIds);
        try {
            $driveActivityEvents = $repoClass->find($queryBuilder, false);
        } catch (Throwable $throwable) {
            $this->logRecordingFailure('read contexts', null, $throwable);
            return new DriveActivityEvents();
        }
        return $driveActivityEvents instanceof DriveActivityEvents ? $driveActivityEvents : new DriveActivityEvents();
    }

    /** Deletes the activity of a space (the erase of a space); nothing without the table */
    public function deleteActivityOfSpace(int $driveSpaceId): void
    {
        if (!$this->isActivityTableAvailable()) {
            return;
        }
        DBDriveActivityEvent::createQueryBuilder()->delete(DBDriveActivityEvent::BASE_ORM_MODEL, 'activity')
            ->where('activity.driveSpaceId = :driveSpaceId')->setParameter('driveSpaceId', $driveSpaceId)->getQuery()->execute();
    }

    /**
     * Whether the activity table exists: checked once per process (a table added later is noticed by the next
     * process, e.g. after the workers' restart); false when the application leaves it out of its schema.
     */
    public function isActivityTableAvailable(): bool
    {
        $tableName = DBDriveActivityEvent::BASE_ORM_MODEL::getTableName();
        if (!array_key_exists($tableName, static::$isTableAvailableByTableName)) {
            try {
                static::$isTableAvailableByTableName[$tableName] = !EntityModelGeneratorService::isExcludedSqlTableName($tableName)
                    && EntityManagerFactory::getInstance()->getConnection()->createSchemaManager()->tablesExist([$tableName]);
            } catch (Throwable) {
                static::$isTableAvailableByTableName[$tableName] = false;
            }
        }
        return static::$isTableAvailableByTableName[$tableName];
    }

    /** Forgets the table check (tests, a schema applied in the same process) */
    public static function forgetActivityTableCheck(): void
    {
        static::$isTableAvailableByTableName = [];
    }

    protected function hasRecentAccess(DriveNode $driveNode, ?int $driveFileVersionId, string $eventType, ?int $actorAccountId): bool
    {
        $dedupSeconds = Config::get('CloudDrive.settings.accessEventDedupSeconds');
        $dedupSeconds = is_numeric($dedupSeconds) ? (int)$dedupSeconds : static::DEFAULT_ACCESS_EVENT_DEDUP_SECONDS;
        if ($dedupSeconds <= 0) {
            return false;
        }
        $queryBuilder = DBDriveActivityEvent::createQueryBuilder(true);
        $alias = DBDriveActivityEvent::getBaseModelAlias();
        $queryBuilder->select("COUNT({$alias}.id)")
            ->andWhere("{$alias}.driveNodeId = :driveNodeId")
            ->andWhere("{$alias}.eventType = :eventType")
            ->andWhere("{$alias}.created >= :since")
            ->setParameter('driveNodeId', (int)$driveNode->id)
            ->setParameter('eventType', $eventType)
            ->setParameter('since', DateTime::fromTimestamp(time() - $dedupSeconds)->format('Y-m-d H:i:s'));
        $queryBuilder->andWhere($driveFileVersionId !== null ? "{$alias}.driveFileVersionId = :driveFileVersionId" : "{$alias}.driveFileVersionId IS NULL");
        if ($driveFileVersionId !== null) {
            $queryBuilder->setParameter('driveFileVersionId', $driveFileVersionId);
        }
        $queryBuilder->andWhere($actorAccountId !== null ? "{$alias}.actorAccountId = :actorAccountId" : "{$alias}.actorAccountId IS NULL");
        if ($actorAccountId !== null) {
            $queryBuilder->setParameter('actorAccountId', $actorAccountId);
        }
        return (int)$queryBuilder->getQuery()->getSingleScalarResult() > 0;
    }

    protected function buildActivityEvent(DriveNode $driveNode, string $eventType, ?int $actorAccountId, ?int $onBehalfOfAccountId, string $audience): DriveActivityEvent
    {
        $driveActivityEvent = DriveActivityEvent::newInstance();
        $driveActivityEvent->driveSpaceId = (int)$driveNode->driveSpaceId;
        $driveActivityEvent->driveNodeId = isset($driveNode->id) ? (int)$driveNode->id : null;
        $driveActivityEvent->eventType = $eventType;
        $driveActivityEvent->actorAccountId = $actorAccountId;
        $driveActivityEvent->onBehalfOfAccountId = $onBehalfOfAccountId;
        $driveActivityEvent->audience = $audience;
        return $driveActivityEvent;
    }

    protected function storeActivityEvent(DriveActivityEvent $driveActivityEvent, ?DriveNode $driveNode): ?DriveActivityEvent
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var DriveActivityEvent $storedActivityEvent */
            $storedActivityEvent = $driveActivityEvent->insert();
            return $storedActivityEvent;
        } catch (Throwable $throwable) {
            $this->logRecordingFailure('write ' . $driveActivityEvent->eventType, $driveNode, $throwable);
            return null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function findActingAccountId(): ?int
    {
        $authAccount = AuthService::instance()->getAccount();
        return $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
    }

    protected function logRecordingFailure(string $operation, ?DriveNode $driveNode, Throwable $throwable): void
    {
        DDDService::instance()->getLogger()->warning('[CloudDrive] activity not recorded', [
            'operation' => $operation,
            'driveNodeId' => $driveNode?->id,
            'reason' => $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 500),
        ]);
    }
}
