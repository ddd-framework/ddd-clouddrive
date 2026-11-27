<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\External;

use DDD\Domain\CloudDrive\Entities\External\DriveExternalSpace;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalSpaces;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see DriveExternalSpace}.
 *
 * @method DriveExternalSpace|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DriveExternalSpaces findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DriveExternalSpace update(DriveExternalSpace &$entity)
 */
class DriveExternalSpacesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = DriveExternalSpace::class;
}
