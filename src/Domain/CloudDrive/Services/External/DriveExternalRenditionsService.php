<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\External;

use DDD\Domain\CloudDrive\Entities\External\DriveExternalRendition;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalRenditions;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see DriveExternalRendition}.
 *
 * @method DriveExternalRendition|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DriveExternalRenditions findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DriveExternalRendition update(DriveExternalRendition &$entity)
 */
class DriveExternalRenditionsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = DriveExternalRendition::class;
}
