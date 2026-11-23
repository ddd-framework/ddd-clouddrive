<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Transfers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkSkip;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkSkips;

/**
 * What a transfer to another space did: the nodes it moved (source id → target id), the ones it left with the reason;
 * `isQueued` when it runs as a job (`CloudDrive.settings.asyncTransfer`) and nothing is done yet.
 */
class DriveSpaceTransferResult extends ValueObject
{
    public bool $isQueued = false;

    /** @var int Given nodes moved (not their subtrees) */
    public int $processedCount = 0;

    public int $fileCount = 0;

    public int $folderCount = 0;

    public DriveTransferredNodes $transferredNodes;

    public DriveNodeBulkSkips $skipped;

    public function __construct()
    {
        parent::__construct();
        $this->transferredNodes = new DriveTransferredNodes();
        $this->skipped = new DriveNodeBulkSkips();
    }

    public function addSkip(int $driveNodeId, string $reason): void
    {
        $driveNodeBulkSkip = new DriveNodeBulkSkip();
        $driveNodeBulkSkip->driveNodeId = $driveNodeId;
        $driveNodeBulkSkip->reason = $reason;
        $this->skipped->add($driveNodeBulkSkip);
    }

    public function addTransferredNode(int $sourceDriveNodeId, int $targetDriveNodeId): void
    {
        $driveTransferredNode = new DriveTransferredNode();
        $driveTransferredNode->sourceDriveNodeId = $sourceDriveNodeId;
        $driveTransferredNode->targetDriveNodeId = $targetDriveNodeId;
        $this->transferredNodes->add($driveTransferredNode);
    }
}
