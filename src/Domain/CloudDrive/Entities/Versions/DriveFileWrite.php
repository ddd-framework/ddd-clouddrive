<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Versions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;

/**
 * One write of a file: the blob with the new content and the version's facts. `expectedLatestVersionId` is the base
 * of a compare and swap: the node's write head must still be this version (null: the file must not exist yet);
 * `ignoresExpectedVersion` writes on top of whatever is there ("mine").
 */
class DriveFileWrite extends ValueObject
{
    public int $driveBlobId = 0;

    public ?string $comment = null;

    public string $origin = DriveNode::ORIGIN_UPLOAD;

    public bool $isContentUntrusted = false;

    public ?int $expectedLatestVersionId = null;

    public bool $ignoresExpectedVersion = false;

    public ?int $authorAccountId = null;

    public ?int $onBehalfOfAccountId = null;

    public ?string $conversationKey = null;

    public ?string $workingCopyKey = null;

    public ?int $copiedFromNodeId = null;

    public ?int $copiedFromVersionId = null;
}
