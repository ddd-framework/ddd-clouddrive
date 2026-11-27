<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Repo\DB\External;

use DDD\Domain\CloudDrive\Entities\External\DriveExternalItem;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DriveExternalItem find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistrCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DriveExternalItem update(DriveExternalItem &$entity, int $depth = 1)
 * @property DBDriveExternalItemModel $ormObject
 */
class DBDriveExternalItem extends DBEntity
{
    public const string BASE_ENTITY_CLASS = DriveExternalItem::class;
    public const string BASE_ORM_MODEL = DBDriveExternalItemModel::class;

    /** System rows: read and written only with the rights restrictions switched off (services check the access) */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $queryBuilder->andWhere(static::getBaseModelAlias() . '.id IS NULL');
        return true;
    }

    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyReadRightsQuery($queryBuilder);
    }

    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyReadRightsQuery($queryBuilder);
    }

    /** Nobody inserts a row while the rights restrictions are on (services write with them switched off) */
    public static function applyCreateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $queryBuilder->andWhere('1 = 0');
        return true;
    }
}
