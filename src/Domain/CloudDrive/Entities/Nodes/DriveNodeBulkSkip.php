<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ValueObject;

/** A node a bulk action left as it was, and why */
class DriveNodeBulkSkip extends ValueObject
{
    public int $driveNodeId = 0;

    public string $reason = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->driveNodeId);
    }
}
