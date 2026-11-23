<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The quota of a space and what counts against it: every stored blob — each kept version of a file and everything in
 * the trash — until the maintenance purges it (its bytes are given back by the blob sweep). A blob several versions
 * share counts once.
 */
class DriveSpaceQuota extends ValueObject
{
    /** @var int Bytes stored (and reserved by running uploads) */
    public int $usedBytes = 0;

    /** @var int The space's limit (its own, else its kind's) */
    public int $quotaBytes = 0;

    /** @var int The largest file the space accepts */
    public int $maxFileSizeBytes = 0;

    /** @var int Live files */
    public int $fileCount = 0;

    /** @var int Live folders */
    public int $folderCount = 0;

    /** @var int Days a trashed node stays restorable */
    public int $trashRetentionDays = 0;

    /** @var int Newest versions of a file that are always kept */
    public int $keptVersionCount = 0;

    /** @var int Days an older version beyond the kept ones stays */
    public int $versionRetentionDays = 0;
}
