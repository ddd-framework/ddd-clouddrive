<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferLostLinks;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferredNodes;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The host side of a move between spaces (registered by this tag, autoconfigured), called through
 * {@see DriveSpaceTransfersService}:
 *
 * - {@see findLinksLostOnTransfer()}: for the preview — which links of the application the nodes lose in the target
 *   (e.g. a link to a record the target's owner cannot see);
 * - {@see onNodesTransferred()}: after the move, before the source nodes are purged — re-point the application's rows
 *   from the source ids to the target ids (`$driveTransferredNodes`) and drop the links the target cannot hold.
 *
 * A listener that throws is logged and skipped (the preview without its links; the move stays done).
 */
#[AutoconfigureTag(DriveSpaceTransferListener::TAG)]
interface DriveSpaceTransferListener
{
    public const string TAG = 'clouddrive.space_transfer_listener';

    /**
     * @param DriveNodes $driveNodes the nodes that would move, subtrees included
     */
    public function findLinksLostOnTransfer(DriveNodes $driveNodes, DriveSpace $targetDriveSpace): DriveTransferLostLinks;

    /**
     * @param DriveNodes $driveNodes the source nodes that moved, subtrees included (their rows go right after)
     * @param DriveTransferredNodes $driveTransferredNodes source id → target id of every moved node
     */
    public function onNodesTransferred(DriveNodes $driveNodes, DriveSpace $sourceDriveSpace, DriveSpace $targetDriveSpace, DriveTransferredNodes $driveTransferredNodes): void;
}
