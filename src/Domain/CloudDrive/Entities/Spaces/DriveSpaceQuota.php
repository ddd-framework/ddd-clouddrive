<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The quota of a space and what counts against it: every stored blob — each kept version of a file and everything in
 * the trash — until the maintenance purges it (its bytes are given back by the blob sweep). A blob several versions
 * share counts once.
 *
 * `usedBytes` is split into `currentVersionBytes` + `olderVersionBytes` + `trashedBytes` (released blobs, each once,
 * {@see DriveSpaceUsage}) + `pendingBytes` (the rest of the counter: uploads in flight, content waiting for or refused
 * by its scan, blobs marked for purge). The four sum to `usedBytes`; when the counter lags behind the released blobs
 * (a drifted counter), `pendingBytes` is 0 and the three released parts are reported as they are.
 */
class DriveSpaceQuota extends ValueObject
{
    /** @var int Bytes stored (and reserved by running uploads) */
    public int $usedBytes = 0;

    /** @var int The space's limit (its own, else its kind's) */
    public int $quotaBytes = 0;

    /** @var int The same limit under its explicit name (the effective quota: the space's own, else its kind's) */
    public int $effectiveQuotaBytes = 0;

    /** @var int Bytes of the current versions of live files */
    public int $currentVersionBytes = 0;

    /** @var int Bytes only older versions of live files still hold (and released blobs waiting for the sweep) */
    public int $olderVersionBytes = 0;

    /** @var int Bytes of files in the trash */
    public int $trashedBytes = 0;

    /** @var int Bytes not released: uploads in flight, waiting for or refused by the scan, marked for purge */
    public int $pendingBytes = 0;

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
