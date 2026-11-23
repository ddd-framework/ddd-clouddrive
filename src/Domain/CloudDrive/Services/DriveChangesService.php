<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent;
use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvents;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEventListItem;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEventListItems;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvents;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Changes\DBDriveChangeEvent;
use DDD\Domain\CloudDrive\Repo\DB\Changes\DBDriveChangeEvents;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;

/**
 * The change feed of the spaces: every create, version, move, rename, trash, restore and copy is one event; the id is
 * the cursor a working copy pulls from (`findChangesSince()`).
 *
 * @method DriveChangeEvent|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DBDriveChangeEvent getEntityRepoClassInstance()
 * @method DBDriveChangeEvents getEntitySetRepoClassInstance()
 */
class DriveChangesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DriveChangeEvent::class;

    public const int MAX_CHANGES_PER_PAGE = 5000;

    public const int DEFAULT_NODE_HISTORY_LIMIT = 20;

    public const int MAX_NODE_HISTORY_LIMIT = 200;

    /**
     * Records one change of a node; the caller has checked the right already. `$onBehalfOfAccountId` (the account the
     * actor acted for) and a SHARED audience are kept in the change's context (the activity table, when it exists).
     */
    public function recordChange(
        DriveNode $driveNode,
        string $eventType,
        ?int $driveFileVersionId = null,
        ?string $path = null,
        ?string $previousPath = null,
        ?string $workingCopyKey = null,
        ?int $onBehalfOfAccountId = null
    ): DriveChangeEvent {
        $driveChangeEvent = DriveChangeEvent::newInstance();
        $driveChangeEvent->driveSpaceId = (int)$driveNode->driveSpaceId;
        $driveChangeEvent->driveNodeId = isset($driveNode->id) ? (int)$driveNode->id : null;
        $driveChangeEvent->eventType = $eventType;
        $driveChangeEvent->driveFileVersionId = $driveFileVersionId;
        $driveChangeEvent->path = $path !== null ? mb_substr($path, 0, 2000) : null;
        $driveChangeEvent->previousPath = $previousPath !== null ? mb_substr($previousPath, 0, 2000) : null;
        $authAccount = AuthService::instance()->getAccount();
        $driveChangeEvent->actorAccountId = $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
        $driveChangeEvent->workingCopyKey = $workingCopyKey;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var DriveChangeEvent $driveChangeEvent */
            $driveChangeEvent = $driveChangeEvent->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $this->getDriveActivityEventsService()->recordChangeContext(
            $driveChangeEvent,
            $onBehalfOfAccountId ?? $this->findOnBehalfOfAccountIdOfCurrentCaller(),
            $this->getDriveSpacesService()->getCurrentAudience()
        );
        return $driveChangeEvent;
    }

    /**
     * The account the current caller acts for when a change does not say it (e.g. a staff member signed in as a
     * partner, an agent working for the space's owner). Default: none. Override in the application (same-path
     * subclass) with its impersonation or delegation rule.
     */
    public function findOnBehalfOfAccountIdOfCurrentCaller(): ?int
    {
        return null;
    }

    /**
     * Records an event of the application on a node (e.g. `LINKED`, `UNLINKED`, `IMPORTED_TO_MEDIA_LIBRARY`): its own
     * type (upper case, digits and `_`, 2 to 64 characters, none of the drive's types), a short detail, who acted and
     * for whom (the actor defaults to the signed-in account). It shows in the node's history among the changes. Kept in
     * the activity table: without it (code before schema) nothing is recorded and null is answered.
     *
     * @throws BadRequestException
     */
    public function recordHostEvent(DriveNode $driveNode, string $eventType, ?string $detail = null, ?int $actorAccountId = null, ?int $onBehalfOfAccountId = null): ?DriveActivityEvent
    {
        if ($actorAccountId === null) {
            $authAccount = AuthService::instance()->getAccount();
            $actorAccountId = $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
        }
        return $this->getDriveActivityEventsService()->recordHostEvent($driveNode, $eventType, $detail, $actorAccountId, $onBehalfOfAccountId ?? $this->findOnBehalfOfAccountIdOfCurrentCaller(), $this->getDriveSpacesService()->getCurrentAudience());
    }

    /**
     * A node's history, newest first: its changes, its accesses and the application's events, at most `$limit`.
     * `$eventTypes` keeps these types only; `$eventGroup` keeps `changes` (everything but accesses) or `accesses`
     * (VIEWED, DOWNLOADED, READ_BY_AGENT).
     *
     * @param string[]|null $eventTypes
     * @throws BadRequestException
     */
    public function buildNodeHistory(DriveNode $driveNode, int $limit = self::DEFAULT_NODE_HISTORY_LIMIT, ?array $eventTypes = null, ?string $eventGroup = null): DriveChangeEventListItems
    {
        if ($eventGroup !== null && !in_array($eventGroup, [DriveChangeEvent::EVENT_GROUP_CHANGES, DriveChangeEvent::EVENT_GROUP_ACCESSES], true)) {
            throw new BadRequestException(sprintf('eventGroup is %s or %s.', DriveChangeEvent::EVENT_GROUP_CHANGES, DriveChangeEvent::EVENT_GROUP_ACCESSES));
        }
        $limit = max(1, min(self::MAX_NODE_HISTORY_LIMIT, $limit));
        $driveChangeEvents = $this->findChangesOfNode($driveNode, $limit, $eventTypes, $eventGroup);
        $activityEventTypes = $eventTypes;
        if ($eventGroup === DriveChangeEvent::EVENT_GROUP_ACCESSES) {
            $activityEventTypes = array_values(array_intersect($eventTypes ?? DriveChangeEvent::ACCESS_EVENT_TYPES, DriveChangeEvent::ACCESS_EVENT_TYPES));
        }
        $driveActivityEvents = $this->getDriveActivityEventsService()->findActivityEventsOfNode($driveNode, $limit, $activityEventTypes);
        if ($eventGroup === DriveChangeEvent::EVENT_GROUP_CHANGES) {
            $keptActivityEvents = new DriveActivityEvents();
            foreach ($driveActivityEvents->getElements() as $driveActivityEvent) {
                if (!in_array($driveActivityEvent->eventType, DriveChangeEvent::ACCESS_EVENT_TYPES, true)) {
                    $keptActivityEvents->add($driveActivityEvent);
                }
            }
            $driveActivityEvents = $keptActivityEvents;
        }
        return $this->buildHistoryListItems($driveChangeEvents, $driveActivityEvents, $limit);
    }

    /**
     * The changes of a space after the cursor, oldest first, at most `$limit`; changes the working copy made itself
     * are left out when its key is given.
     */
    public function findChangesSince(int $driveSpaceId, int $afterChangeId, int $limit = self::MAX_CHANGES_PER_PAGE, ?string $excludedWorkingCopyKey = null): DriveChangeEvents
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.id > :afterChangeId")
            ->setParameter('driveSpaceId', $driveSpaceId)
            ->setParameter('afterChangeId', $afterChangeId)
            ->orderBy("{$alias}.id", 'ASC')
            ->setMaxResults(max(1, min(self::MAX_CHANGES_PER_PAGE, $limit)));
        if ($excludedWorkingCopyKey !== null) {
            $queryBuilder->andWhere("({$alias}.workingCopyKey IS NULL OR {$alias}.workingCopyKey <> :excludedWorkingCopyKey)")
                ->setParameter('excludedWorkingCopyKey', $excludedWorkingCopyKey);
        }
        $driveChangeEvents = $repoClass->find($queryBuilder, false);
        return $driveChangeEvents instanceof DriveChangeEvents ? $driveChangeEvents : new DriveChangeEvents();
    }

    /**
     * The newest changes of one node, newest first, under the caller's rights; only `$eventTypes` when given, only the
     * group's types with `$eventGroup` ({@see buildNodeHistory()}).
     *
     * @param string[]|null $eventTypes
     */
    public function findChangesOfNode(DriveNode $driveNode, int $limit = self::DEFAULT_NODE_HISTORY_LIMIT, ?array $eventTypes = null, ?string $eventGroup = null): DriveChangeEvents
    {
        if ($eventTypes === []) {
            return new DriveChangeEvents();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.driveNodeId = :driveNodeId")
            ->setParameter('driveSpaceId', (int)$driveNode->driveSpaceId)
            ->setParameter('driveNodeId', (int)$driveNode->id)
            ->orderBy("{$alias}.id", 'DESC')
            ->setMaxResults(max(1, min(self::MAX_NODE_HISTORY_LIMIT, $limit)));
        if ($eventTypes !== null) {
            $queryBuilder->andWhere("{$alias}.eventType IN (:eventTypes)")->setParameter('eventTypes', array_values($eventTypes));
        }
        if ($eventGroup === DriveChangeEvent::EVENT_GROUP_ACCESSES) {
            $queryBuilder->andWhere("{$alias}.eventType IN (:accessEventTypes)")->setParameter('accessEventTypes', DriveChangeEvent::ACCESS_EVENT_TYPES);
        } elseif ($eventGroup === DriveChangeEvent::EVENT_GROUP_CHANGES) {
            $queryBuilder->andWhere("{$alias}.eventType NOT IN (:accessEventTypes)")->setParameter('accessEventTypes', DriveChangeEvent::ACCESS_EVENT_TYPES);
        }
        $driveChangeEvents = $repoClass->find($queryBuilder, false);
        return $driveChangeEvents instanceof DriveChangeEvents ? $driveChangeEvents : new DriveChangeEvents();
    }

    /** The history entries of changes (with their context, when recorded) */
    public function buildChangeEventListItems(DriveChangeEvents $driveChangeEvents): DriveChangeEventListItems
    {
        return $this->buildHistoryListItems($driveChangeEvents, new DriveActivityEvents(), self::MAX_CHANGES_PER_PAGE);
    }

    /**
     * Changes and activities as one history, newest first, at most `$limit`; actor names as the current audience sees
     * them ({@see DriveActorNamesService}).
     */
    protected function buildHistoryListItems(DriveChangeEvents $driveChangeEvents, DriveActivityEvents $driveActivityEvents, int $limit): DriveChangeEventListItems
    {
        $driveChangeEventIds = [];
        foreach ($driveChangeEvents->getElements() as $driveChangeEvent) {
            $driveChangeEventIds[] = (int)$driveChangeEvent->id;
        }
        $changeContextsByChangeEventId = [];
        foreach ($this->getDriveActivityEventsService()->findContextsOfChangeEvents($driveChangeEventIds)->getElements() as $changeContext) {
            $changeContextsByChangeEventId[(int)$changeContext->driveChangeEventId] = $changeContext;
        }
        $accountIds = [];
        foreach ($driveChangeEvents->getElements() as $driveChangeEvent) {
            $accountIds[] = (int)$driveChangeEvent->actorAccountId;
            $accountIds[] = (int)($changeContextsByChangeEventId[(int)$driveChangeEvent->id]->onBehalfOfAccountId ?? 0);
        }
        foreach ($driveActivityEvents->getElements() as $driveActivityEvent) {
            $accountIds[] = (int)$driveActivityEvent->actorAccountId;
            $accountIds[] = (int)$driveActivityEvent->onBehalfOfAccountId;
        }
        $audience = $this->getDriveSpacesService()->getCurrentAudience();
        /** @var DriveActorNamesService $driveActorNamesService */
        $driveActorNamesService = DDDService::instance()->getService(DriveActorNamesService::class);
        $accountDisplayNames = $driveActorNamesService->findActorDisplayNames($accountIds, $audience);
        $historyListItems = [];
        foreach ($driveChangeEvents->getElements() as $driveChangeEvent) {
            $changeContext = $changeContextsByChangeEventId[(int)$driveChangeEvent->id] ?? null;
            $driveChangeEventListItem = new DriveChangeEventListItem();
            $driveChangeEventListItem->id = (int)$driveChangeEvent->id;
            $driveChangeEventListItem->eventSource = DriveChangeEventListItem::SOURCE_CHANGE;
            $driveChangeEventListItem->eventType = (string)$driveChangeEvent->eventType;
            $driveChangeEventListItem->occurredAt = $driveChangeEvent->getCreatedTime();
            $driveChangeEventListItem->actorAccountId = $driveChangeEvent->actorAccountId;
            $driveChangeEventListItem->onBehalfOfAccountId = $changeContext?->onBehalfOfAccountId;
            $driveChangeEventListItem->audience = $changeContext?->audience;
            $driveChangeEventListItem->path = $driveChangeEvent->path;
            $driveChangeEventListItem->previousPath = $driveChangeEvent->previousPath;
            $driveChangeEventListItem->driveFileVersionId = $driveChangeEvent->driveFileVersionId;
            $driveChangeEventListItem->isFromWorkingCopy = $driveChangeEvent->workingCopyKey !== null;
            $this->applyActorNames($driveChangeEventListItem, $driveActorNamesService, $audience, $accountDisplayNames);
            $historyListItems[] = $driveChangeEventListItem;
        }
        foreach ($driveActivityEvents->getElements() as $driveActivityEvent) {
            $driveChangeEventListItem = new DriveChangeEventListItem();
            $driveChangeEventListItem->id = (int)$driveActivityEvent->id;
            $driveChangeEventListItem->eventSource = DriveChangeEventListItem::SOURCE_ACTIVITY;
            $driveChangeEventListItem->eventType = (string)$driveActivityEvent->eventType;
            $driveChangeEventListItem->occurredAt = $driveActivityEvent->getCreatedTime();
            $driveChangeEventListItem->actorAccountId = $driveActivityEvent->actorAccountId;
            $driveChangeEventListItem->onBehalfOfAccountId = $driveActivityEvent->onBehalfOfAccountId;
            $driveChangeEventListItem->audience = $driveActivityEvent->audience;
            $driveChangeEventListItem->contentVariant = $driveActivityEvent->contentVariant;
            $driveChangeEventListItem->detail = $driveActivityEvent->detail;
            // the client's IP is personal data of the person who accessed: staff only, never in a SHARED answer
            $driveChangeEventListItem->clientIp = $audience === DriveSpace::AUDIENCE_INTERNAL ? ($driveActivityEvent->clientIp ?? null) : null;
            $driveChangeEventListItem->driveFileVersionId = $driveActivityEvent->driveFileVersionId;
            $this->applyActorNames($driveChangeEventListItem, $driveActorNamesService, $audience, $accountDisplayNames);
            $historyListItems[] = $driveChangeEventListItem;
        }
        usort($historyListItems, [$this, 'compareHistoryListItemsNewestFirst']);
        $driveChangeEventListItems = new DriveChangeEventListItems();
        foreach (array_slice($historyListItems, 0, max(1, $limit)) as $driveChangeEventListItem) {
            $driveChangeEventListItems->add($driveChangeEventListItem);
        }
        return $driveChangeEventListItems;
    }

    /** Newest first; at the same second, activities (an access, an application's event) after the change they follow */
    public function compareHistoryListItemsNewestFirst(DriveChangeEventListItem $firstListItem, DriveChangeEventListItem $secondListItem): int
    {
        $timeComparison = ($secondListItem->occurredAt?->getTimestamp() ?? 0) <=> ($firstListItem->occurredAt?->getTimestamp() ?? 0);
        if ($timeComparison !== 0) {
            return $timeComparison;
        }
        if ($firstListItem->eventSource !== $secondListItem->eventSource) {
            return $firstListItem->eventSource === DriveChangeEventListItem::SOURCE_ACTIVITY ? -1 : 1;
        }
        return $secondListItem->id <=> $firstListItem->id;
    }

    /**
     * @param string[] $accountDisplayNames names as the audience sees them, keyed by account id
     */
    protected function applyActorNames(DriveChangeEventListItem $driveChangeEventListItem, DriveActorNamesService $driveActorNamesService, string $audience, array $accountDisplayNames): void
    {
        $driveChangeEventListItem->actorName = $driveChangeEventListItem->actorAccountId !== null
            ? $driveActorNamesService->buildActorDisplayName($driveChangeEventListItem->actorAccountId, $driveChangeEventListItem->onBehalfOfAccountId, $audience, $accountDisplayNames)
            : null;
        $driveChangeEventListItem->onBehalfOfName = $driveChangeEventListItem->onBehalfOfAccountId !== null
            ? ($accountDisplayNames[$driveChangeEventListItem->onBehalfOfAccountId] ?? null)
            : null;
    }

    protected function getDriveActivityEventsService(): DriveActivityEventsService
    {
        /** @var DriveActivityEventsService $driveActivityEventsService */
        $driveActivityEventsService = DDDService::instance()->getService(DriveActivityEventsService::class);
        return $driveActivityEventsService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    /** The newest change id of a space; 0 without changes */
    public function findNewestChangeId(int $driveSpaceId): int
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->select("MAX({$alias}.id)")
            ->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->setParameter('driveSpaceId', $driveSpaceId);
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Ids of access events (`DriveChangeEvent::ACCESS_EVENT_TYPES`, e.g. `READ_BY_AGENT`) in the change table created
     * before `$createdBefore`, oldest first; changes of other types are never selected.
     *
     * @return int[]
     */
    public function findAccessEventIdsCreatedBefore(DateTime $createdBefore, int $limit): array
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->select("{$alias}.id")
            ->andWhere("{$alias}.eventType IN (:accessEventTypes)")
            ->andWhere("{$alias}.created < :createdBefore")
            ->setParameter('accessEventTypes', DriveChangeEvent::ACCESS_EVENT_TYPES)
            ->setParameter('createdBefore', $createdBefore->format('Y-m-d H:i:s'))
            ->orderBy("{$alias}.id", 'ASC')
            ->setMaxResults(max(1, $limit));
        return array_values(array_map('intval', $queryBuilder->getQuery()->getSingleColumnResult()));
    }

    /**
     * The number of access events in the change table created before `$createdBefore` (a dry run of the retention).
     */
    public function countAccessEventsCreatedBefore(DateTime $createdBefore): int
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->select("COUNT({$alias}.id)")
            ->andWhere("{$alias}.eventType IN (:accessEventTypes)")
            ->andWhere("{$alias}.created < :createdBefore")
            ->setParameter('accessEventTypes', DriveChangeEvent::ACCESS_EVENT_TYPES)
            ->setParameter('createdBefore', $createdBefore->format('Y-m-d H:i:s'));
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Deletes access events of the change table by id; an id of another event type is left alone.
     *
     * @param int[] $driveChangeEventIds
     * @return int rows deleted
     */
    public function deleteAccessEventsByIds(array $driveChangeEventIds): int
    {
        $driveChangeEventIds = array_values(array_unique(array_filter(array_map('intval', $driveChangeEventIds))));
        if ($driveChangeEventIds === []) {
            return 0;
        }
        return (int)DBDriveChangeEvent::createQueryBuilder()->delete(DBDriveChangeEvent::BASE_ORM_MODEL, 'changeEvent')
            ->where('changeEvent.id IN (:driveChangeEventIds)')
            ->andWhere('changeEvent.eventType IN (:accessEventTypes)')
            ->setParameter('driveChangeEventIds', $driveChangeEventIds)
            ->setParameter('accessEventTypes', DriveChangeEvent::ACCESS_EVENT_TYPES)
            ->getQuery()->execute();
    }
}
