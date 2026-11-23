<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferLostLink;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferLostLinks;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferredNodes;
use DDD\Domain\CloudDrive\Services\DriveSpaceTransferListener;
use RuntimeException;

/** Reports one lost link per node it is shown; throws when asked to */
class LinkReportingDriveSpaceTransferListener implements DriveSpaceTransferListener
{
    public ?DriveTransferredNodes $transferredNodes = null;

    public function __construct(public bool $throwsOnPreview = false)
    {
    }

    public function findLinksLostOnTransfer(DriveNodes $driveNodes, DriveSpace $targetDriveSpace): DriveTransferLostLinks
    {
        if ($this->throwsOnPreview) {
            throw new RuntimeException('listener down');
        }
        $driveTransferLostLinks = new DriveTransferLostLinks();
        foreach ($driveNodes->getElements() as $driveNode) {
            $driveTransferLostLink = new DriveTransferLostLink();
            $driveTransferLostLink->driveNodeId = (int)$driveNode->id;
            $driveTransferLostLink->linkKind = 'record';
            $driveTransferLostLink->linkKey = '7';
            $driveTransferLostLinks->add($driveTransferLostLink);
        }
        return $driveTransferLostLinks;
    }

    public function onNodesTransferred(DriveNodes $driveNodes, DriveSpace $sourceDriveSpace, DriveSpace $targetDriveSpace, DriveTransferredNodes $driveTransferredNodes): void
    {
        $this->transferredNodes = $driveTransferredNodes;
    }
}
