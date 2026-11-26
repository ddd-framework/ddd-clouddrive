<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Imports;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveImportedFile[] $elements
 * @method DriveImportedFile|null first()
 * @method DriveImportedFile[] getElements()
 */
class DriveImportedFiles extends ObjectSet
{
}
