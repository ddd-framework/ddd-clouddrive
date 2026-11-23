<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferLostLinks;
use DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy;
use DDD\Domain\CloudDrive\Services\DriveSpaceTransfersService;

/** The transfer service with its database-free steps reachable from a test */
class ExposedDriveSpaceTransfersService extends DriveSpaceTransfersService
{
    public function findFileRefusalReasonForTest(DriveNode $driveNode, DriveTypePolicy $driveTypePolicy): ?string
    {
        return $this->findFileRefusalReason($driveNode, $driveTypePolicy);
    }

    public function findLinksLostOnTransferForTest(DriveNodes $driveNodes, DriveSpace $targetDriveSpace): DriveTransferLostLinks
    {
        return $this->findLinksLostOnTransfer($driveNodes, $targetDriveSpace);
    }
}
