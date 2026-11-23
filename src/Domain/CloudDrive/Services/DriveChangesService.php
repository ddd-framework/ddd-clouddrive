<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEventListItem;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEventListItems;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvents;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Repo\DB\Changes\DBDriveChangeEvent;
use DDD\Domain\CloudDrive\Repo\DB\Changes\DBDriveChangeEvents;
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

    /** Records one change of a node; the caller has checked the right already */
    public function recordChange(
        DriveNode $driveNode,
        string $eventType,
        ?int $driveFileVersionId = null,
        ?string $path = null,
        ?string $previousPath = null,
        ?string $workingCopyKey = null
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
        return $driveChangeEvent;
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

    /** The newest changes of one node, newest first, under the caller's rights */
    public function findChangesOfNode(DriveNode $driveNode, int $limit = self::DEFAULT_NODE_HISTORY_LIMIT): DriveChangeEvents
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.driveNodeId = :driveNodeId")
            ->setParameter('driveSpaceId', (int)$driveNode->driveSpaceId)
            ->setParameter('driveNodeId', (int)$driveNode->id)
            ->orderBy("{$alias}.id", 'DESC')
            ->setMaxResults(max(1, min(self::MAX_NODE_HISTORY_LIMIT, $limit)));
        $driveChangeEvents = $repoClass->find($queryBuilder, false);
        return $driveChangeEvents instanceof DriveChangeEvents ? $driveChangeEvents : new DriveChangeEvents();
    }

    /** The history entries of changes, with the actors' display names ({@see DriveNodesService::findAccountDisplayNames()}) */
    public function buildChangeEventListItems(DriveChangeEvents $driveChangeEvents): DriveChangeEventListItems
    {
        $actorAccountIds = [];
        foreach ($driveChangeEvents->getElements() as $driveChangeEvent) {
            if ($driveChangeEvent->actorAccountId !== null) {
                $actorAccountIds[] = (int)$driveChangeEvent->actorAccountId;
            }
        }
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        $accountDisplayNames = $driveNodesService->findAccountDisplayNames($actorAccountIds);
        $driveChangeEventListItems = new DriveChangeEventListItems();
        foreach ($driveChangeEvents->getElements() as $driveChangeEvent) {
            $driveChangeEventListItem = new DriveChangeEventListItem();
            $driveChangeEventListItem->id = (int)$driveChangeEvent->id;
            $driveChangeEventListItem->eventType = (string)$driveChangeEvent->eventType;
            $driveChangeEventListItem->occurredAt = $driveChangeEvent->getCreatedTime();
            $driveChangeEventListItem->actorAccountId = $driveChangeEvent->actorAccountId;
            $driveChangeEventListItem->actorName = $driveChangeEvent->actorAccountId !== null ? ($accountDisplayNames[(int)$driveChangeEvent->actorAccountId] ?? null) : null;
            $driveChangeEventListItem->path = $driveChangeEvent->path;
            $driveChangeEventListItem->previousPath = $driveChangeEvent->previousPath;
            $driveChangeEventListItem->driveFileVersionId = $driveChangeEvent->driveFileVersionId;
            $driveChangeEventListItem->isFromWorkingCopy = $driveChangeEvent->workingCopyKey !== null;
            $driveChangeEventListItems->add($driveChangeEventListItem);
        }
        return $driveChangeEventListItems;
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
}
