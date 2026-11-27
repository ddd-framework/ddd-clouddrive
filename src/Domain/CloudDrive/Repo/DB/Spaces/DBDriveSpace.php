<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\Spaces;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpaceRightsExtension;
use DDD\Domain\CloudDrive\Services\DriveSpaceRightsExtensionsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;

/**
 * The one place that knows who may do what in a drive: read, write (upload, organise, trash, restore, commit) and
 * manage (settings, purge, erase). Write implies read, manage implies write. Every drive table filters through
 * {@see applyRightsToSpaceQuery()}.
 *
 * The default: an admin account may do everything, every other caller nothing. A host adds its rules as
 * {@see DriveSpaceRightsExtension}s (tagged services, composed by {@see DriveSpaceRightsExtensionsService}); it never
 * subclasses DriveSpace, this repository or its model — a second Doctrine model on the same table breaks the drive's
 * relations (unsupported).
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
     * were added, false when the caller has the right on every space. The host's rules come from its
     * {@see DriveSpaceRightsExtension}s; without one, an admin account has every right and every other caller none.
     */
    public static function applyRightsToSpaceQuery(DoctrineQueryBuilder &$queryBuilder, string $spaceAlias, string $right): bool
    {
        $authAccount = AuthService::instance()->getAccount();
        $isAdminAccount = $authAccount !== null && ($authAccount->roles?->isAdmin() ?? false);
        $driveSpaceRightsExtensionsService = static::getDriveSpaceRightsExtensionsService();
        if ($driveSpaceRightsExtensionsService->isCallerUnrestricted($authAccount, $right, $isAdminAccount)) {
            return false;
        }
        return $driveSpaceRightsExtensionsService->applyGrantedSpacesCondition($queryBuilder, $spaceAlias, $right, $authAccount);
    }

    /**
     * Leaves out EXTERNAL spaces (roots of a drive connection): they are never reached through the drive's own
     * rights, not even by an unrestricted caller; their services authorize them through their connection and run
     * with the rights switched off.
     */
    public static function applyExternalSpaceExclusion(DoctrineQueryBuilder &$queryBuilder, string $spaceAlias): void
    {
        $queryBuilder->andWhere("{$spaceAlias}.spaceKind <> :{$spaceAlias}_externalSpaceKind")->setParameter("{$spaceAlias}_externalSpaceKind", DriveSpace::EXTERNAL_SPACE_KIND);
    }

    public static function getDriveSpaceRightsExtensionsService(): DriveSpaceRightsExtensionsService
    {
        /** @var DriveSpaceRightsExtensionsService $driveSpaceRightsExtensionsService */
        $driveSpaceRightsExtensionsService = DDDService::instance()->getService(DriveSpaceRightsExtensionsService::class);
        return $driveSpaceRightsExtensionsService;
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
        static::applyExternalSpaceExclusion($queryBuilder, static::getBaseModelAlias());
        static::applyRightsToSpaceQuery($queryBuilder, static::getBaseModelAlias(), self::RIGHT_READ);
        return true;
    }

    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        static::applyExternalSpaceExclusion($queryBuilder, static::getBaseModelAlias());
        static::applyRightsToSpaceQuery($queryBuilder, static::getBaseModelAlias(), self::RIGHT_MANAGE);
        return true;
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
