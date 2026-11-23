<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveSpaceUsage[] $elements
 * @method DriveSpaceUsage[] getElements()
 * @method DriveSpaceUsage|null getByUniqueKey(string $uniqueKey)
 */
class DriveSpaceUsages extends ObjectSet
{
}
