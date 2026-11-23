<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Versions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The values a system note of a version carries ({@see DriveFileVersionListItem::$commentCode}), for a client that
 * renders the note in its own language. Internal ids never appear here; a value the caller may not see is null.
 */
class DriveFileVersionCommentParameters extends ValueObject
{
    /** @var int|null RESTORED_FROM_VERSION: the number of the version that was restored */
    public ?int $versionNumber = null;

    /** @var string|null TRANSFERRED_FROM_SPACE: the display name of the space the file came from (staff only, null when not readable) */
    public ?string $sourceSpaceDisplayName = null;

    /** @var string|null COPIED_FROM_PATH: the workspace path the file was copied from (staff only) */
    public ?string $sourcePath = null;
}
