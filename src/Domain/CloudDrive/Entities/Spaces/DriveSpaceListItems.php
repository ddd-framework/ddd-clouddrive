<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveSpaceListItem[] $elements
 * @method DriveSpaceListItem[] getElements()
 * @method DriveSpaceListItem|null getByUniqueKey(string $uniqueKey)
 */
class DriveSpaceListItems extends ObjectSet
{
}
