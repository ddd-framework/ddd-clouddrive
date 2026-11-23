<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveNodeListItem[] $elements
 * @method DriveNodeListItem[] getElements()
 * @method DriveNodeListItem|null getByUniqueKey(string $uniqueKey)
 */
class DriveNodeListItems extends ObjectSet
{
}
