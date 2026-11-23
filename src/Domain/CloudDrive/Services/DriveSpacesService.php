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
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceUsage;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaces;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Messenger\WorkerStateResetterInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Spaces: creation with their root folder, the rights check of one space, quota reservations and counters. Quota and
 * counters change with one atomic statement each, never through `update()`.
 *
 * The audience of the current caller ({@see getCurrentAudience()}): INTERNAL (the staff, the default) or SHARED (the
 * people a shared space is shared with, e.g. partners). Every rights check for WRITE and MANAGE goes through
 * {@see isRightAllowedForAudience()} on top of the host's space rights: a SHARED caller never manages, and writes only
 * where {@see isWriteAllowedForAudience()} allows it (not while `isAudienceWriteLocked`). The controller trait sets the
 * audience per request (`getDriveAudience()`), the agent tools per call; it is reset to INTERNAL between requests and
 * Messenger jobs.
 *
 * @method DriveSpace|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DriveSpaces findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDriveSpace getEntityRepoClassInstance()
 * @method DBDriveSpaces getEntitySetRepoClassInstance()
 */
class DriveSpacesService extends EntitiesService implements WorkerStateResetterInterface, ResetInterface
{
    public const DEFAULT_ENTITY_CLASS = DriveSpace::class;

    /** @var string[] The audiences a caller acts as */
    public const array AUDIENCES = [DriveSpace::AUDIENCE_INTERNAL, DriveSpace::AUDIENCE_SHARED];

    /** @var string The audience of the current caller ({@see enableAudience()}) */
    protected string $currentAudience = DriveSpace::AUDIENCE_INTERNAL;

    /** The audience the current caller acts as: INTERNAL (staff) or SHARED */
    public function getCurrentAudience(): string
    {
        return $this->currentAudience;
    }

    /**
     * Makes the current caller act as `$audience` and answers the audience before (restore it with
     * {@see restoreAudience()} in `finally`, or leave it for the rest of a request).
     *
     * @throws BadRequestException
     */
    public function enableAudience(string $audience): string
    {
        if (!in_array($audience, static::AUDIENCES, true)) {
            throw new BadRequestException(sprintf('Unknown drive audience "%s"; one of %s.', $audience, implode(', ', static::AUDIENCES)));
        }
        $previousAudience = $this->currentAudience;
        $this->currentAudience = $audience;
        return $previousAudience;
    }

    public function restoreAudience(string $previousAudience): void
    {
        $this->currentAudience = in_array($previousAudience, static::AUDIENCES, true) ? $previousAudience : DriveSpace::AUDIENCE_INTERNAL;
    }

    /**
     * Whether `$audience` may write in the space at all (the host's rights decide who of that audience may). Default:
     * the staff always; the shared audience unless the space is write-locked for it. Override in the application
     * (same-path subclass) for other rules (e.g. a write switch per partner).
     */
    public function isWriteAllowedForAudience(DriveSpace $driveSpace, string $audience): bool
    {
        if ($audience === DriveSpace::AUDIENCE_INTERNAL) {
            return true;
        }
        return !(bool)$driveSpace->isAudienceWriteLocked;
    }

    /** Whether `$audience` may hold `$right` on the space: READ always, WRITE per {@see isWriteAllowedForAudience()}, MANAGE only the staff */
    public function isRightAllowedForAudience(DriveSpace $driveSpace, string $right, string $audience): bool
    {
        return match ($right) {
            DBDriveSpace::RIGHT_READ => true,
            DBDriveSpace::RIGHT_WRITE => $this->isWriteAllowedForAudience($driveSpace, $audience),
            default => $audience === DriveSpace::AUDIENCE_INTERNAL,
        };
    }

    /** A Messenger job starts as the staff */
    public function resetWorkerStateForNewJob(): void
    {
        $this->currentAudience = DriveSpace::AUDIENCE_INTERNAL;
    }

    /** A request of a long-running runtime starts as the staff (kernel.reset) */
    public function reset(): void
    {
        $this->currentAudience = DriveSpace::AUDIENCE_INTERNAL;
    }

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
        if (!$driveSpace instanceof DriveSpace) {
            return null;
        }
        return $this->isRightAllowedForAudience($driveSpace, $right, $this->currentAudience) ? $driveSpace : null;
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
        $allowedSpaceIds = array_values(array_map('intval', $queryBuilder->getQuery()->getSingleColumnResult()));
        if ($right === DBDriveSpace::RIGHT_READ || $this->currentAudience === DriveSpace::AUDIENCE_INTERNAL || $allowedSpaceIds === []) {
            return $allowedSpaceIds;
        }
        $driveSpaceSetRepo = $this->getEntitySetRepoClassInstance();
        $spaceQueryBuilder = $driveSpaceSetRepo::createQueryBuilder(true);
        $spaceAlias = $driveSpaceSetRepo::getBaseModelAlias();
        $spaceQueryBuilder->andWhere("{$spaceAlias}.id IN (:driveSpaceIds)")->setParameter('driveSpaceIds', $allowedSpaceIds);
        $driveSpaces = $driveSpaceSetRepo->find($spaceQueryBuilder, false);
        $audienceSpaceIds = [];
        foreach ($driveSpaces instanceof DriveSpaces ? $driveSpaces->getElements() : [] as $driveSpace) {
            if ($this->isRightAllowedForAudience($driveSpace, $right, $this->currentAudience)) {
                $audienceSpaceIds[] = (int)$driveSpace->id;
            }
        }
        return $audienceSpaceIds;
    }

    /**
     * The quota of a space, its counters and the split of the used bytes (what counts: {@see DriveSpaceQuota}). Without
     * `$driveSpaceUsage` the split is read for this space alone.
     */
    public function buildSpaceQuota(DriveSpace $driveSpace, ?DriveSpaceUsage $driveSpaceUsage = null): DriveSpaceQuota
    {
        $driveSpaceQuota = new DriveSpaceQuota();
        $driveSpaceQuota->usedBytes = (int)$driveSpace->usedBytes;
        $driveSpaceQuota->quotaBytes = $driveSpace->getEffectiveQuotaBytes();
        $driveSpaceQuota->effectiveQuotaBytes = $driveSpaceQuota->quotaBytes;
        $driveSpaceQuota->maxFileSizeBytes = $driveSpace->getEffectiveMaxFileSizeBytes();
        $driveSpaceQuota->fileCount = (int)$driveSpace->fileCount;
        $driveSpaceQuota->folderCount = (int)$driveSpace->folderCount;
        $driveSpaceQuota->trashRetentionDays = $driveSpace->getTrashRetentionDays();
        $driveSpaceQuota->keptVersionCount = $driveSpace->getKeptVersionCount();
        $driveSpaceQuota->versionRetentionDays = $driveSpace->getVersionRetentionDays();
        $driveSpaceUsage ??= $this->getDriveBlobsService()->findSpaceUsages([(int)$driveSpace->id])->getByUniqueKey(DriveSpaceUsage::uniqueKeyStatic((string)(int)$driveSpace->id));
        $this->applySpaceUsageToQuota($driveSpaceQuota, $driveSpaceUsage);
        return $driveSpaceQuota;
    }

    /** Splits the quota's used bytes into the released parts of `$driveSpaceUsage` and the pending rest */
    public function applySpaceUsageToQuota(DriveSpaceQuota $driveSpaceQuota, ?DriveSpaceUsage $driveSpaceUsage): void
    {
        $driveSpaceQuota->currentVersionBytes = $driveSpaceUsage?->currentVersionBytes ?? 0;
        $driveSpaceQuota->olderVersionBytes = $driveSpaceUsage?->olderVersionBytes ?? 0;
        $driveSpaceQuota->trashedBytes = $driveSpaceUsage?->trashedBytes ?? 0;
        $driveSpaceQuota->pendingBytes = max(
            0,
            $driveSpaceQuota->usedBytes - $driveSpaceQuota->currentVersionBytes - $driveSpaceQuota->olderVersionBytes - $driveSpaceQuota->trashedBytes
        );
    }

    /**
     * The spaces as a space picker lists them: display name, group, quota and the caller's write and manage rights, in
     * the order of {@see compareSpaceListItems()}.
     */
    public function buildSpaceListItems(DriveSpaces $driveSpaces): DriveSpaceListItems
    {
        $driveSpaceIds = [];
        foreach ($driveSpaces->getElements() as $driveSpace) {
            $driveSpaceIds[] = (int)$driveSpace->id;
        }
        $writableSpaceIds = array_flip($this->findSpaceIdsWithRight($driveSpaceIds, DBDriveSpace::RIGHT_WRITE));
        $managedSpaceIds = array_flip($this->findSpaceIdsWithRight($driveSpaceIds, DBDriveSpace::RIGHT_MANAGE));
        $driveSpaceUsages = $this->getDriveBlobsService()->findSpaceUsages($driveSpaceIds);
        $driveSpaceListItems = new DriveSpaceListItems();
        foreach ($driveSpaces->getElements() as $driveSpace) {
            $driveSpaceListItem = $this->buildSpaceListItem(
                $driveSpace,
                isset($writableSpaceIds[(int)$driveSpace->id]),
                isset($managedSpaceIds[(int)$driveSpace->id]),
                // a space without released blobs has no usage row: its split is all pending, no second query
                $driveSpaceUsages->getByUniqueKey(DriveSpaceUsage::uniqueKeyStatic((string)(int)$driveSpace->id)) ?? new DriveSpaceUsage()
            );
            $driveSpaceListItems->add($driveSpaceListItem);
        }
        $driveSpaceListItems->sort([$this, 'compareSpaceListItems']);
        return $driveSpaceListItems;
    }

    /** One space as a picker shows it; without `$driveSpaceUsage` the split of its used bytes is read for it alone */
    public function buildSpaceListItem(DriveSpace $driveSpace, bool $canWrite, bool $canManage, ?DriveSpaceUsage $driveSpaceUsage = null): DriveSpaceListItem
    {
        $driveSpaceListItem = new DriveSpaceListItem();
        $driveSpaceListItem->id = (int)$driveSpace->id;
        $driveSpaceListItem->title = $driveSpace->title;
        $driveSpaceListItem->displayName = $this->buildSpaceDisplayName($driveSpace);
        $driveSpaceListItem->groupLabel = $this->buildSpaceGroupLabel($driveSpace);
        $driveSpaceListItem->displayGroup = $this->buildSpaceDisplayGroup($driveSpace);
        $driveSpaceListItem->spaceKind = $driveSpace->spaceKind;
        $driveSpaceListItem->audience = $driveSpace->audience;
        $driveSpaceListItem->rootNodeId = $driveSpace->rootNodeId;
        $driveSpaceListItem->quota = $this->buildSpaceQuota($driveSpace, $driveSpaceUsage);
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

    /**
     * A code of the space's group for ordering and icons: the kind in upper case by default (`default` → `DEFAULT`),
     * null without a kind. Override in the application with its own codes.
     */
    public function buildSpaceDisplayGroup(DriveSpace $driveSpace): ?string
    {
        $spaceKind = trim((string)$driveSpace->spaceKind);
        return $spaceKind !== '' ? strtoupper($spaceKind) : null;
    }

    /**
     * The order of a space list (`spaces/list`): by {@see getSpaceDisplayGroupRank()} of the group code, then the group
     * code, then the display name (case-insensitive, natural), then the id. Override the rank for the application's
     * group order, or this comparison for a different order altogether.
     */
    public function compareSpaceListItems(DriveSpaceListItem $driveSpaceListItem, DriveSpaceListItem $otherDriveSpaceListItem): int
    {
        return [
                $this->getSpaceDisplayGroupRank($driveSpaceListItem->displayGroup),
                (string)$driveSpaceListItem->displayGroup,
                mb_strtolower($driveSpaceListItem->displayName),
                $driveSpaceListItem->id,
            ] <=> [
                $this->getSpaceDisplayGroupRank($otherDriveSpaceListItem->displayGroup),
                (string)$otherDriveSpaceListItem->displayGroup,
                mb_strtolower($otherDriveSpaceListItem->displayName),
                $otherDriveSpaceListItem->id,
            ];
    }

    /**
     * The rank of a group code in a space list (lower first). Default: the order of the kinds in
     * `config/app/CloudDrive/spaceKinds.php` (a code is the kind in upper case), unknown codes after them, no group last.
     */
    public function getSpaceDisplayGroupRank(?string $displayGroup): int
    {
        if ($displayGroup === null || $displayGroup === '') {
            return PHP_INT_MAX;
        }
        $spaceKinds = Config::get('CloudDrive.spaceKinds');
        $rank = 0;
        foreach (is_array($spaceKinds) ? array_keys($spaceKinds) : [] as $spaceKind) {
            if (strtoupper((string)$spaceKind) === $displayGroup) {
                return $rank;
            }
            $rank++;
        }
        return PHP_INT_MAX - 1;
    }

    /** The model class of the application's space repository */
    public function getSpaceModelClass(): string
    {
        /** @var DBDriveSpace $driveSpaceRepoClass */
        $driveSpaceRepoClass = DBDriveSpaceScopedEntity::getDriveSpaceRepoClass();
        return $driveSpaceRepoClass::BASE_ORM_MODEL;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }
}
