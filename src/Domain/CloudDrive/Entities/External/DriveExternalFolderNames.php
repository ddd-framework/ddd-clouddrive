<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\External;

use DDD\Domain\Base\Entities\ValueObject;

/** The filesystem names already given in one folder of an EXTERNAL space during one listing (case-insensitive) */
class DriveExternalFolderNames extends ValueObject
{
    /** @var string[] lower-case names */
    public array $takenNames = [];

    public function isTaken(string $filesystemName): bool
    {
        return in_array(mb_strtolower($filesystemName), $this->takenNames, true);
    }

    public function take(string $filesystemName): void
    {
        if (!$this->isTaken($filesystemName)) {
            $this->takenNames[] = mb_strtolower($filesystemName);
        }
    }
}
