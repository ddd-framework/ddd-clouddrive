<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Content;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A short-lived URL that reads one version's bytes from the store, granted under the reader's rights and the purpose
 * (the variant): DOWNLOAD (the original as an attachment), INLINE (a preview; only JPEG, PNG, GIF, WebP, and an SVG
 * through the host's sanitiser), THUMBNAIL (a list's picture, the same bytes as INLINE, never logged as an access) or
 * WORKSPACE_RESTORE (unreleased content back into its own working copy, never shown to people).
 */
class DriveContentGrant extends ValueObject
{
    public const string PURPOSE_DOWNLOAD = 'DOWNLOAD';
    public const string PURPOSE_INLINE = 'INLINE';
    public const string PURPOSE_THUMBNAIL = 'THUMBNAIL';
    public const string PURPOSE_WORKSPACE_RESTORE = 'WORKSPACE_RESTORE';
    public const array PURPOSES = [self::PURPOSE_DOWNLOAD, self::PURPOSE_INLINE, self::PURPOSE_THUMBNAIL, self::PURPOSE_WORKSPACE_RESTORE];

    /** @var string[] The purposes people are granted */
    public const array PEOPLE_PURPOSES = [self::PURPOSE_DOWNLOAD, self::PURPOSE_INLINE, self::PURPOSE_THUMBNAIL];

    public string $purpose = self::PURPOSE_DOWNLOAD;

    public string $url = '';

    public ?DateTime $expiresAt = null;

    public string $fileName = '';

    public ?int $sizeBytes = null;

    public ?string $contentType = null;

    public ?string $sha256 = null;

    /** @var int|null The file the content belongs to */
    public ?int $driveNodeId = null;

    /** @var int|null The version whose content the URL reads */
    public ?int $driveFileVersionId = null;

    /** @var bool True when the URL serves the inline variant (a preview), false for an attachment */
    public bool $isInline = false;
}
