<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Versions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One version of a file as a version history draws it: number, size, comment, author, scan status of its blob, and
 * whether it is the one the file shows (`isCurrent`) or its newest (`isLatest`). `isRestorable`: its content is
 * released and can become the newest version again.
 */
class DriveFileVersionListItem extends ValueObject
{
    /** A version made by restoring an older one (parameter versionNumber) */
    public const string COMMENT_CODE_RESTORED_FROM_VERSION = 'RESTORED_FROM_VERSION';

    /** A file moved here from another space (parameter sourceSpaceDisplayName, staff only) */
    public const string COMMENT_CODE_TRANSFERRED_FROM_SPACE = 'TRANSFERRED_FROM_SPACE';

    /** A file the agent copied here from a workspace path (parameter sourcePath, staff only) */
    public const string COMMENT_CODE_COPIED_FROM_PATH = 'COPIED_FROM_PATH';

    /** @var string[] Every system note a version can carry */
    public const array COMMENT_CODES = [
        self::COMMENT_CODE_RESTORED_FROM_VERSION,
        self::COMMENT_CODE_TRANSFERRED_FROM_SPACE,
        self::COMMENT_CODE_COPIED_FROM_PATH,
    ];

    public int $id = 0;

    public int $driveNodeId = 0;

    public int $versionNumber = 0;

    public ?int $sizeBytes = null;

    public ?string $contentType = null;

    /** @var string|null Free text a person or the agent wrote; null when the version carries a system note ({@see $commentCode}) */
    public ?string $comment = null;

    /** @var string|null A system note the client renders in its own language ({@see COMMENT_CODES}); null for free text */
    public ?string $commentCode = null;

    /** @var DriveFileVersionCommentParameters|null The values of the system note */
    public ?DriveFileVersionCommentParameters $commentParameters = null;

    /** @var string|null UPLOAD, AGENT, COPY or MIGRATED */
    public ?string $origin = null;

    public ?int $authorAccountId = null;

    public ?string $authorName = null;

    public ?DateTime $createdAt = null;

    /** @var string|null {@see \DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob::SCAN_STATUSES} */
    public ?string $scanStatus = null;

    /** @var string|null The scanner's finding (a signature name) or why the scan could not run */
    public ?string $scanDetail = null;

    public bool $isCurrent = false;

    public bool $isLatest = false;

    public bool $isRestorable = false;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->id);
    }
}
