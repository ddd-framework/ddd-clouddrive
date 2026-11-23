<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Transfers;

use DDD\Domain\Base\Entities\ValueObject;

/** A file or folder a transfer moved: its id in the source space (gone afterwards) and in the target space */
class DriveTransferredNode extends ValueObject
{
    public int $sourceDriveNodeId = 0;

    public int $targetDriveNodeId = 0;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->sourceDriveNodeId);
    }
}
