<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceListItem;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceListItems;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceQuota;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaces;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;

/**
 * Spaces: creation with their root folder, the rights check of one space, quota reservations and counters. Quota and
 * counters change with one atomic statement each, never through `update()`.
 *
 * @method DriveSpace|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DriveSpaces findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDriveSpace getEntityRepoClassInstance()
 * @method DBDriveSpaces getEntitySetRepoClassInstance()
 */
class DriveSpacesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DriveSpace::class;

    /**
     * Stores a new space (an instance of the application's class: `DriveSpace::newInstance()`) and its root folder.
     * The caller decided that the space may exist (a resolver, an admin command); no rights apply to the creation.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function createSpace(DriveSpace $driveSpace): DriveSpace
    {
        if (!in_array($driveSpace->audience, [DriveSpace::AUDIENCE_INTERNAL, DriveSpace::AUDIENCE_SHARED], true)) {
            throw new BadRequestException('A drive space needs the audience INTERNAL or SHARED.');
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveSpace->usedBytes = 0;
            $driveSpace->fileCount = 0;
            $driveSpace->folderCount = 0;
            /** @var DriveSpace $driveSpace */
            $driveSpace = $driveSpace->update();
            $rootNode = DriveNode::newInstance();
            $rootNode->driveSpaceId = (int)$driveSpace->id;
            $rootNode->parentNodeId = null;
            $rootNode->nodeType = DriveNode::NODE_TYPE_FOLDER;
            $rootNode->name = '';
            $rootNode->nameSlot = '';
            $rootNode->nameFolded = '';
            /** @var DriveNode $rootNode */
            $rootNode = $rootNode->update();
            $driveSpace->rootNodeId = (int)$rootNode->id;
            $driveSpace->updatePartialIgnoringRights('rootNodeId');
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $driveSpace;
    }

    /**
     * The space when the caller has `$right` on it (READ, WRITE, MANAGE of {@see DBDriveSpace}); "not found" when not
     * even reading is allowed, "forbidden" when reading is but the right is not.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function requireSpaceWithRight(int $driveSpaceId, string $right = DBDriveSpace::RIGHT_READ): DriveSpace
    {
        $driveSpace = $this->findSpaceWithRight($driveSpaceId, DBDriveSpace::RIGHT_READ);
        if ($driveSpace === null) {
            throw new NotFoundException(sprintf('Drive space %d not found.', $driveSpaceId));
        }
        if ($right !== DBDriveSpace::RIGHT_READ && $this->findSpaceWithRight($driveSpaceId, $right) === null) {
            throw new ForbiddenException(sprintf('No %s right on drive space %d.', strtolower($right), $driveSpaceId));
        }
        return $driveSpace;
    }

    /** True when the caller has `$right` on the space */
    public function hasRightOnSpace(DriveSpace $driveSpace, string $right): bool
    {
        return isset($driveSpace->id) && $this->findSpaceWithRight((int)$driveSpace->id, $right) !== null;
    }

    /** The space when the caller has `$right` on it, read fresh (no registry cache) */
    public function findSpaceWithRight(int $driveSpaceId, string $right): ?DriveSpace
    {
        /** @var DBDriveSpace $driveSpaceRepoClass */
        $driveSpaceRepoClass = DBDriveSpaceScopedEntity::getDriveSpaceRepoClass();
        $driveSpaceRepo = new $driveSpaceRepoClass();
        $queryBuilder = $driveSpaceRepoClass::createQueryBuilder(true);
        $alias = $driveSpaceRepoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.id = :driveSpaceId")->setParameter('driveSpaceId', $driveSpaceId);
        if ($right !== DBDriveSpace::RIGHT_READ) {
            $driveSpaceRepoClass::applyRightsToSpaceQueryWhenRestricted($queryBuilder, $alias, $right);
        }
        $driveSpace = $driveSpaceRepo->find($queryBuilder, false);
        return $driveSpace instanceof DriveSpace ? $driveSpace : null;
    }

    /** The throwaway space of an eval case's workspace key, null when there is none */
    public function findSpaceByScratchKey(string $scratchKey): ?DriveSpace
    {
        /** @var DBDriveSpace $driveSpaceRepoClass */
        $driveSpaceRepoClass = DBDriveSpaceScopedEntity::getDriveSpaceRepoClass();
        $driveSpaceRepo = new $driveSpaceRepoClass();
        $queryBuilder = $driveSpaceRepoClass::createQueryBuilder(true);
        $alias = $driveSpaceRepoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.scratchKey = :scratchKey")->setParameter('scratchKey', $scratchKey)->setMaxResults(1);
        $driveSpace = $driveSpaceRepo->find($queryBuilder, false);
        return $driveSpace instanceof DriveSpace ? $driveSpace : null;
    }

    /**
     * Reserves bytes against the quota in one statement; false when the quota would be exceeded.
     */
    public function reserveBytes(DriveSpace $driveSpace, int $byteCount): bool
    {
        if ($byteCount <= 0) {
            return true;
        }
        $modelClass = $this->getSpaceModelClass();
        $affectedRows = (int)DBDriveSpace::createQueryBuilder()
            ->update($modelClass, 'space')
            ->set('space.usedBytes', 'space.usedBytes + :byteCount')
            ->where('space.id = :driveSpaceId')
            ->andWhere('space.usedBytes + :byteCount <= :quotaBytes')
            ->setParameter('byteCount', $byteCount)
            ->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->setParameter('quotaBytes', $driveSpace->getEffectiveQuotaBytes())
            ->getQuery()->execute();
        if ($affectedRows === 1) {
            $driveSpace->usedBytes = (int)$driveSpace->usedBytes + $byteCount;
        }
        return $affectedRows === 1;
    }

    /** Gives reserved or used bytes back (never below 0) */
    public function releaseBytes(DriveSpace|int $driveSpace, int $byteCount): void
    {
        if ($byteCount <= 0) {
            return;
        }
        DBDriveSpace::createQueryBuilder()
            ->update($this->getSpaceModelClass(), 'space')
            ->set('space.usedBytes', 'CASE WHEN space.usedBytes > :byteCount THEN space.usedBytes - :byteCount ELSE 0 END')
            ->where('space.id = :driveSpaceId')
            ->setParameter('byteCount', $byteCount)
            ->setParameter('driveSpaceId', $driveSpace instanceof DriveSpace ? (int)$driveSpace->id : $driveSpace)
            ->getQuery()->execute();
    }

    /** Moves the file and folder counters by the given deltas */
    public function adjustCounters(int $driveSpaceId, int $fileDelta, int $folderDelta): void
    {
        if ($fileDelta === 0 && $folderDelta === 0) {
            return;
        }
        DBDriveSpace::createQueryBuilder()
            ->update($this->getSpaceModelClass(), 'space')
            ->set('space.fileCount', 'CASE WHEN space.fileCount + :fileDelta > 0 THEN space.fileCount + :fileDelta ELSE 0 END')
            ->set('space.folderCount', 'CASE WHEN space.folderCount + :folderDelta > 0 THEN space.folderCount + :folderDelta ELSE 0 END')
            ->where('space.id = :driveSpaceId')
            ->setParameter('fileDelta', $fileDelta)
            ->setParameter('folderDelta', $folderDelta)
            ->setParameter('driveSpaceId', $driveSpaceId)
            ->getQuery()->execute();
    }

    /**
     * The ids among `$driveSpaceIds` the caller has `$right` on (one query for a whole list).
     *
     * @param int[] $driveSpaceIds
     * @return int[]
     */
    public function findSpaceIdsWithRight(array $driveSpaceIds, string $right): array
    {
        $driveSpaceIds = array_values(array_unique(array_filter(array_map('intval', $driveSpaceIds))));
        if ($driveSpaceIds === []) {
            return [];
        }
        /** @var DBDriveSpace $driveSpaceRepoClass */
        $driveSpaceRepoClass = DBDriveSpaceScopedEntity::getDriveSpaceRepoClass();
        $queryBuilder = $driveSpaceRepoClass::createQueryBuilder(true);
        $alias = $driveSpaceRepoClass::getBaseModelAlias();
        $queryBuilder->select("{$alias}.id")->andWhere("{$alias}.id IN (:driveSpaceIds)")->setParameter('driveSpaceIds', $driveSpaceIds);
        $driveSpaceRepoClass::applyRightsToSpaceQueryWhenRestricted($queryBuilder, $alias, $right);
        return array_values(array_map('intval', $queryBuilder->getQuery()->getSingleColumnResult()));
    }

    /** The quota of a space and its counters (what counts: {@see DriveSpaceQuota}) */
    public function buildSpaceQuota(DriveSpace $driveSpace): DriveSpaceQuota
    {
        $driveSpaceQuota = new DriveSpaceQuota();
        $driveSpaceQuota->usedBytes = (int)$driveSpace->usedBytes;
        $driveSpaceQuota->quotaBytes = $driveSpace->getEffectiveQuotaBytes();
        $driveSpaceQuota->maxFileSizeBytes = $driveSpace->getEffectiveMaxFileSizeBytes();
        $driveSpaceQuota->fileCount = (int)$driveSpace->fileCount;
        $driveSpaceQuota->folderCount = (int)$driveSpace->folderCount;
        $driveSpaceQuota->trashRetentionDays = $driveSpace->getTrashRetentionDays();
        $driveSpaceQuota->keptVersionCount = $driveSpace->getKeptVersionCount();
        $driveSpaceQuota->versionRetentionDays = $driveSpace->getVersionRetentionDays();
        return $driveSpaceQuota;
    }

    /** The spaces as a space picker lists them: display name, group, quota and the caller's write and manage rights */
    public function buildSpaceListItems(DriveSpaces $driveSpaces): DriveSpaceListItems
    {
        $driveSpaceIds = [];
        foreach ($driveSpaces->getElements() as $driveSpace) {
            $driveSpaceIds[] = (int)$driveSpace->id;
        }
        $writableSpaceIds = array_flip($this->findSpaceIdsWithRight($driveSpaceIds, DBDriveSpace::RIGHT_WRITE));
        $managedSpaceIds = array_flip($this->findSpaceIdsWithRight($driveSpaceIds, DBDriveSpace::RIGHT_MANAGE));
        $driveSpaceListItems = new DriveSpaceListItems();
        foreach ($driveSpaces->getElements() as $driveSpace) {
            $driveSpaceListItem = $this->buildSpaceListItem($driveSpace, isset($writableSpaceIds[(int)$driveSpace->id]), isset($managedSpaceIds[(int)$driveSpace->id]));
            $driveSpaceListItems->add($driveSpaceListItem);
        }
        return $driveSpaceListItems;
    }

    /** One space as a picker shows it */
    public function buildSpaceListItem(DriveSpace $driveSpace, bool $canWrite, bool $canManage): DriveSpaceListItem
    {
        $driveSpaceListItem = new DriveSpaceListItem();
        $driveSpaceListItem->id = (int)$driveSpace->id;
        $driveSpaceListItem->title = $driveSpace->title;
        $driveSpaceListItem->displayName = $this->buildSpaceDisplayName($driveSpace);
        $driveSpaceListItem->groupLabel = $this->buildSpaceGroupLabel($driveSpace);
        $driveSpaceListItem->spaceKind = $driveSpace->spaceKind;
        $driveSpaceListItem->audience = $driveSpace->audience;
        $driveSpaceListItem->rootNodeId = $driveSpace->rootNodeId;
        $driveSpaceListItem->quota = $this->buildSpaceQuota($driveSpace);
        $driveSpaceListItem->canWrite = $canWrite;
        $driveSpaceListItem->canManage = $canManage;
        return $driveSpaceListItem;
    }

    /**
     * The name a picker shows for the space. Override in the application (same-path subclass of this service) to show
     * the name of what the space belongs to (its anchor).
     */
    public function buildSpaceDisplayName(DriveSpace $driveSpace): string
    {
        return $driveSpace->buildDisplayTitle();
    }

    /**
     * The group a picker lists the space under: the kind's `label` (`config/app/CloudDrive/spaceKinds.php`), else
     * null (no group). Override in the application for its own grouping.
     */
    public function buildSpaceGroupLabel(DriveSpace $driveSpace): ?string
    {
        $groupLabel = $driveSpace->getSpaceKindSetting('label');
        return is_string($groupLabel) && $groupLabel !== '' ? $groupLabel : null;
    }

    /** The model class of the application's space repository */
    public function getSpaceModelClass(): string
    {
        /** @var DBDriveSpace $driveSpaceRepoClass */
        $driveSpaceRepoClass = DBDriveSpaceScopedEntity::getDriveSpaceRepoClass();
        return $driveSpaceRepoClass::BASE_ORM_MODEL;
    }
}
