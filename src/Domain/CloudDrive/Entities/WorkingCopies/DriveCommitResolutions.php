<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveCommitResolution[] $elements
 * @method DriveCommitResolution|null getByUniqueKey(string $uniqueKey)
 * @method DriveCommitResolution[] getElements()
 */
class DriveCommitResolutions extends ObjectSet
{
    public function findByPath(string $path): ?DriveCommitResolution
    {
        return $this->getByUniqueKey(DriveCommitResolution::uniqueKeyStatic($path));
    }
}
