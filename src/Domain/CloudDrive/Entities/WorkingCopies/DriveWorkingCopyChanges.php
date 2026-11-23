<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A pull: the files that changed in the drive since the working copy's cursor (made by others), in their current
 * state, and the cursor to acknowledge once they are applied. `hasMore` asks for another pull.
 */
class DriveWorkingCopyChanges extends ValueObject
{
    public int $fromCursor = 0;

    public int $toCursor = 0;

    public bool $hasMore = false;

    public ?DriveWorkingCopyFiles $changedFiles = null;
}
