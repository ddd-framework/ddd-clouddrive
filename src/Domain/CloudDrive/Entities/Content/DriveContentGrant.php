<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Content;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A short-lived URL that reads one version's bytes from the store, granted under the reader's rights and the purpose:
 * DOWNLOAD (attachment), INLINE (preview; never for HTML or SVG) or WORKSPACE_RESTORE (unreleased content back into
 * its own working copy, never shown to people).
 */
class DriveContentGrant extends ValueObject
{
    public const string PURPOSE_DOWNLOAD = 'DOWNLOAD';
    public const string PURPOSE_INLINE = 'INLINE';
    public const string PURPOSE_WORKSPACE_RESTORE = 'WORKSPACE_RESTORE';
    public const array PURPOSES = [self::PURPOSE_DOWNLOAD, self::PURPOSE_INLINE, self::PURPOSE_WORKSPACE_RESTORE];

    public string $purpose = self::PURPOSE_DOWNLOAD;

    public string $url = '';

    public ?DateTime $expiresAt = null;

    public string $fileName = '';

    public ?int $sizeBytes = null;

    public ?string $contentType = null;

    public ?string $sha256 = null;
}
