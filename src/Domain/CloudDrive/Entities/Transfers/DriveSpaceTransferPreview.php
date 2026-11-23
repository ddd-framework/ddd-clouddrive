<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Transfers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkSkips;

/**
 * What moving files and folders to another space would do, nothing written: how much moves, whether the target's
 * quota takes it, which names are taken there (the moved node gets a free name), what the target refuses (type
 * policy, content not released) and which links of the application are lost.
 */
class DriveSpaceTransferPreview extends ValueObject
{
    public int $sourceDriveSpaceId = 0;

    public int $targetDriveSpaceId = 0;

    /** @var int Files that would move (subtrees included, refused ones left out) */
    public int $fileCount = 0;

    /** @var int Folders that would move (subtrees included) */
    public int $folderCount = 0;

    /** @var int Bytes of the files that would move (the target may need fewer: content it holds already is shared) */
    public int $totalBytes = 0;

    /** @var int What the target's quota has left */
    public int $targetFreeBytes = 0;

    public bool $fitsTargetQuota = true;

    /** @var DriveNodeBulkSkips Names taken in the target folder: the node moves under a free name (`name (2).ext`) */
    public DriveNodeBulkSkips $nameConflicts;

    /** @var DriveNodeBulkSkips Files the target does not take, with the reason: they stay in the source */
    public DriveNodeBulkSkips $refused;

    /** @var DriveTransferLostLinks Links of the application the moved nodes lose */
    public DriveTransferLostLinks $lostLinks;

    /** @var bool True when anything moves and the target's quota takes it */
    public bool $isTransferable = false;

    public function __construct()
    {
        parent::__construct();
        $this->nameConflicts = new DriveNodeBulkSkips();
        $this->refused = new DriveNodeBulkSkips();
        $this->lostLinks = new DriveTransferLostLinks();
    }
}
