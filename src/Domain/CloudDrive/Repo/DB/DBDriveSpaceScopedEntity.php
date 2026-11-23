<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Infrastructure\Services\DDDService;

/**
 * The repository of a drive table whose rows belong to a space (`driveSpaceId`): a row is readable when its space is
 * readable and writable when its space is writable, under the drive's space rights
 * ({@see DBDriveSpace::applyRightsToSpaceQuery()}, extended by the host's DriveSpaceRightsExtension services). No
 * drive row is reachable outside the caller's spaces.
 */
abstract class DBDriveSpaceScopedEntity extends DBEntity
{
    /** @var string The alias of the space inside the rights subquery */
    public const string RIGHTS_SPACE_ALIAS = 'rightsDriveSpace';

    /**
     * The space repository: the module's. A same-path subclass of DriveSpace (kept resolvable for applications that
     * still have one) is unsupported — its second Doctrine model on the same table clashes with the module's relations;
     * hosts express their rights as DriveSpaceRightsExtension services.
     */
    public static function getDriveSpaceRepoClass(): string
    {
        $driveSpaceClassName = DDDService::instance()->getContainerServiceClassNameForClass(DriveSpace::class) ?? DriveSpace::class;
        return $driveSpaceClassName::getRepoClass() ?? DBDriveSpace::class;
    }

    /**
     * Restricts the rows of `$queryBuilder` to the spaces the caller has `$right` on: `driveSpaceId IN (SELECT …)`.
     * True when a restriction was added.
     */
    public static function applySpaceRightsQuery(DoctrineQueryBuilder &$queryBuilder, string $right): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var DBDriveSpace $driveSpaceRepoClass */
        $driveSpaceRepoClass = static::getDriveSpaceRepoClass();
        $spaceModelClass = $driveSpaceRepoClass::BASE_ORM_MODEL;
        $spaceQueryBuilder = $driveSpaceRepoClass::createQueryBuilder();
        $spaceQueryBuilder->select(self::RIGHTS_SPACE_ALIAS . '.id')->from($spaceModelClass, self::RIGHTS_SPACE_ALIAS);
        if (!$driveSpaceRepoClass::applyRightsToSpaceQueryWhenRestricted($spaceQueryBuilder, self::RIGHTS_SPACE_ALIAS, $right)) {
            return false;
        }
        $alias = static::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId IN (" . $spaceQueryBuilder->getDQL() . ')');
        foreach ($spaceQueryBuilder->getParameters() as $parameter) {
            $queryBuilder->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
        }
        return true;
    }

    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applySpaceRightsQuery($queryBuilder, DBDriveSpace::RIGHT_READ);
    }

    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applySpaceRightsQuery($queryBuilder, DBDriveSpace::RIGHT_WRITE);
    }

    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applySpaceRightsQuery($queryBuilder, DBDriveSpace::RIGHT_WRITE);
    }

    public static function applyCreateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applySpaceRightsQuery($queryBuilder, DBDriveSpace::RIGHT_WRITE);
    }
}
