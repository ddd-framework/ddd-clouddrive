<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ValueObject;

/** Files and folders counted by a walk through a subtree (trash, restore, purge) */
class DriveNodeCounts extends ValueObject
{
    public int $fileCount = 0;

    public int $folderCount = 0;
}
