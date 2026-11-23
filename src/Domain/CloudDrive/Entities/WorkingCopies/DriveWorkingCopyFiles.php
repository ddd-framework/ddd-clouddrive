<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Files of a working copy, by path.
 *
 * @property DriveWorkingCopyFile[] $elements
 * @method DriveWorkingCopyFile|null getByUniqueKey(string $uniqueKey)
 * @method DriveWorkingCopyFile[] getElements()
 */
class DriveWorkingCopyFiles extends ObjectSet
{
    public function findByPath(string $path): ?DriveWorkingCopyFile
    {
        return $this->getByUniqueKey(DriveWorkingCopyFile::uniqueKeyStatic($path));
    }
}
