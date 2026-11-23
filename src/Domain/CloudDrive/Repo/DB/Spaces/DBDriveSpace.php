<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Spaces;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Infrastructure\Services\AuthService;

/**
 * The one place that knows who may do what in a drive: read, write (upload, organise, trash, restore, commit) and
 * manage (settings, purge, erase). Write implies read, manage implies write. Every drive table filters through
 * {@see applyRightsToSpaceQuery()}: a host overrides that method in the repository of its `DriveSpace` subclass and
 * adds conditions on the space alias (its anchor columns, its own rights tables, a hierarchy claim).
 *
 * The default: an admin account may do everything, every other caller nothing.
 *
 * @method DriveSpace find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DriveSpace update(DefaultObject &$entity, int $depth = 1)
 */
class DBDriveSpace extends DBEntity
{
    public const string BASE_ENTITY_CLASS = DriveSpace::class;
    public const string BASE_ORM_MODEL = DBDriveSpaceModel::class;

    public const string RIGHT_READ = 'READ';
    public const string RIGHT_WRITE = 'WRITE';
    public const string RIGHT_MANAGE = 'MANAGE';

    /**
     * Adds the conditions under which the caller has `$right` on the space rows of `$spaceAlias`; true when conditions
     * were added, false when the caller has the right on every space. Override in the host's repository.
     */
    public static function applyRightsToSpaceQuery(DoctrineQueryBuilder &$queryBuilder, string $spaceAlias, string $right): bool
    {
        $authAccount = AuthService::instance()->getAccount();
        if ($authAccount !== null && ($authAccount->roles?->isAdmin() ?? false)) {
            return false;
        }
        $queryBuilder->andWhere("{$spaceAlias}.id IS NULL");
        return true;
    }

    /** {@see applyRightsToSpaceQuery()} unless rights are switched off (a system operation) */
    public static function applyRightsToSpaceQueryWhenRestricted(DoctrineQueryBuilder &$queryBuilder, string $spaceAlias, string $right): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        return static::applyRightsToSpaceQuery($queryBuilder, $spaceAlias, $right);
    }

    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        return static::applyRightsToSpaceQuery($queryBuilder, static::getBaseModelAlias(), self::RIGHT_READ);
    }

    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        return static::applyRightsToSpaceQuery($queryBuilder, static::getBaseModelAlias(), self::RIGHT_MANAGE);
    }

    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyUpdateRightsQuery($queryBuilder);
    }

    /** Spaces are created by the module's services after their own checks (a host's resolver, an admin command) */
    public static function applyCreateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return false;
    }
}
