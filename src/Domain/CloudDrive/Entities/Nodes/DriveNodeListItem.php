<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One file or folder as a file manager draws it: the node's facts plus what a list needs beside them — the family,
 * the scan status of what the file shows, a pending newer version, uncommitted working-copy changes, display names of
 * the people, a thumbnail of a released raster image, and in the trash when it is purged.
 */
class DriveNodeListItem extends ValueObject
{
    public int $id = 0;

    public int $driveSpaceId = 0;

    /** @var int|null The folder; null for the root */
    public ?int $parentNodeId = null;

    /** @var string FOLDER, FILE or LINK */
    public string $nodeType = DriveNode::NODE_TYPE_FILE;

    public string $name = '';

    /** @var string Lower case, without the dot ('' for folders and names without one) */
    public string $extension = '';

    /** @var string|null One of {@see DriveFileFamily::FAMILIES}; null for folders */
    public ?string $family = null;

    public ?string $contentType = null;

    /** @var int|null Size of the newest version */
    public ?int $sizeBytes = null;

    public ?int $versionNumber = null;

    /** @var int|null Live children of a folder */
    public ?int $childCount = null;

    public ?string $description = null;

    /** @var string[] */
    public array $tags = [];

    /**
     * @var string|null What the file shows ({@see \DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob::SCAN_STATUSES}):
     *      the released version's, or while none is released the newest version's; null for folders
     */
    public ?string $scanStatus = null;

    /** @var bool A newer version waits for its scan while the current one stays readable */
    public bool $hasPendingVersion = false;

    /** @var int|null The newest version's number, when it is not the current one (waiting, failed or refused) */
    public ?int $latestVersionNumber = null;

    /** @var string|null The newest version's state when it is not the current one: UPLOADING, PENDING, FAILED, INFECTED, BLOCKED, PURGING */
    public ?string $latestVersionScanStatus = null;

    /** @var string|null Why the newest version is not current (the scan's finding, the type policy's refusal), when known */
    public ?string $latestVersionStateReason = null;

    /** @var bool A working copy (an agent sandbox) holds saved, uncommitted changes of this node */
    public bool $hasWorkingCopyChanges = false;

    /** @var bool Written by a third party (a shared space): shown, never trusted */
    public bool $isContentUntrusted = false;

    public bool $isSystem = false;

    /** @var string|null A short-lived inline URL of a released JPEG, PNG, GIF or WebP */
    public ?string $thumbnailUrl = null;

    /** @var bool The server offers a PDF rendition of this released Office file (`GET …/nodes/{id}/rendition`) */
    public bool $hasPdfRendition = false;

    public ?int $createdByAccountId = null;

    /** @var string|null The creator's display name (the application's names; null when it gives none) */
    public ?string $createdByName = null;

    /** @var int|null The account the creator acted for (a staff member signed in as another account); null otherwise */
    public ?int $createdOnBehalfOfAccountId = null;

    /** @var string|null That account's display name */
    public ?string $createdOnBehalfOfName = null;

    public ?DateTime $createdAt = null;

    public ?DateTime $updatedAt = null;

    public ?DateTime $trashedAt = null;

    public ?int $trashedByAccountId = null;

    public ?string $trashedByName = null;

    /** @var DateTime|null Trash only: when the maintenance deletes it for good */
    public ?DateTime $purgeAfter = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->id);
    }
}
