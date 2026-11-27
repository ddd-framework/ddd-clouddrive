<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\External;

use DDD\Domain\CloudDrive\Entities\External\DriveExternalItem;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalItems;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see DriveExternalItem}.
 *
 * @method DriveExternalItem|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DriveExternalItems findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DriveExternalItem update(DriveExternalItem &$entity)
 */
class DriveExternalItemsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = DriveExternalItem::class;
}
