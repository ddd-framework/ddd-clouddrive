<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Transfers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveTransferredNode[] $elements
 * @method DriveTransferredNode[] getElements()
 */
class DriveTransferredNodes extends ObjectSet
{
    /** The target id of a source node; null when it was not moved */
    public function findTargetDriveNodeId(int $sourceDriveNodeId): ?int
    {
        $driveTransferredNode = $this->getByUniqueKey(DriveTransferredNode::uniqueKeyStatic((string)$sourceDriveNodeId));
        return $driveTransferredNode instanceof DriveTransferredNode ? $driveTransferredNode->targetDriveNodeId : null;
    }
}
