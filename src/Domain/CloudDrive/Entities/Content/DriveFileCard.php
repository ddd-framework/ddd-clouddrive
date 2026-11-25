<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Content;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One file card for a viewer: the facts and short-lived content URLs (open inline, download, thumbnail for images),
 * granted under the viewer's rights. Unreleased or blocked content has no URLs.
 */
class DriveFileCard extends ValueObject
{
    public string $reference = '';

    public int $driveSpaceId = 0;

    public string $name = '';

    /** @var string The path in its space */
    public string $path = '';

    public bool $isFolder = false;

    public ?int $sizeBytes = null;

    public ?string $contentType = null;

    public ?DateTime $modifiedAt = null;

    public ?int $versionNumber = null;

    public ?int $childCount = null;

    /** @var string|null `pending scan`, `blocked`, `written by a third party` */
    public ?string $state = null;

    public ?string $inlineUrl = null;

    public ?string $downloadUrl = null;

    public ?string $thumbnailUrl = null;

    public ?DateTime $urlsExpireAt = null;

    /** @var bool The server offers a PDF rendition of this Office file (`GET …/nodes/{id}/rendition`) */
    public bool $hasPdfRendition = false;

    /** @var string|null The host's deep link into its drive screen (`CloudDrive.settings.adminLinkTemplate`); null without a template */
    public ?string $adminLink = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->reference);
    }
}
