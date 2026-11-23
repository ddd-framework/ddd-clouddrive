<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Versions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;

/**
 * What a file write did: the node and the new version, or the conflict (the write head moved past the expected
 * version, or the name is taken by a file written meanwhile) with the newer version.
 */
class DriveFileWriteResult extends ValueObject
{
    public ?DriveNode $driveNode = null;

    public ?DriveFileVersion $driveFileVersion = null;

    public bool $isNewFile = false;

    public bool $isConflict = false;

    public ?int $conflictVersionId = null;

    public ?string $conflictReason = null;

    /** @var bool The content equals the newest version: no version was written */
    public bool $isUnchanged = false;
}
