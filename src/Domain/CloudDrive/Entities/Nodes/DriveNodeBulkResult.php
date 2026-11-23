<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a bulk action on nodes did: the nodes it changed, the files and folders that went along (a folder's subtree),
 * and the nodes it left as they were with the reason.
 */
class DriveNodeBulkResult extends ValueObject
{
    public const int MAX_NODES = 200;

    /** @var int Nodes the action was applied to (the given ones, not their subtrees) */
    public int $processedCount = 0;

    /** @var int Files affected, subtrees included */
    public int $fileCount = 0;

    /** @var int Folders affected, subtrees included */
    public int $folderCount = 0;

    public DriveNodeBulkSkips $skipped;

    /** @var bool More was left to do than one request handles (emptying a large trash): call again */
    public bool $hasMore = false;

    public function __construct()
    {
        $this->skipped = new DriveNodeBulkSkips();
    }

    public function addSkip(int $driveNodeId, string $reason): void
    {
        $driveNodeBulkSkip = new DriveNodeBulkSkip();
        $driveNodeBulkSkip->driveNodeId = $driveNodeId;
        $driveNodeBulkSkip->reason = $reason;
        $this->skipped->add($driveNodeBulkSkip);
    }
}
