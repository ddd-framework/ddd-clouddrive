<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One file of a working copy as the runner keeps it: its path, node, base version and content hash; with a saved
 * change its kind and staged blob, after a commit that left it out the conflict. A pull marks a file the drive
 * trashed or moved away as removed.
 */
class DriveWorkingCopyFile extends ValueObject
{
    public string $path = '';

    public ?string $previousPath = null;

    public ?int $driveNodeId = null;

    public ?int $versionId = null;

    public ?int $versionNumber = null;

    public ?string $sha256 = null;

    public ?int $sizeBytes = null;

    public ?string $contentType = null;

    /** @var int|null The blob that holds the content: the version's (released or pending) or the staged one */
    public ?int $driveBlobId = null;

    /** @var string|null The saved change (ADDED, MODIFIED, DELETED, MOVED); null = clean */
    public ?string $changeKind = null;

    public ?int $stagedDriveBlobId = null;

    public ?string $conflictReason = null;

    public ?int $conflictVersionId = null;

    /** @var bool A pull: the file is gone from the drive (trashed, moved away) */
    public bool $isRemoved = false;

    /** @var bool A system-folder file (`work/`): never listed without `--all` */
    public bool $isSystem = false;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->path);
    }

    public function buildStatusLine(): string
    {
        $lineParts = [$this->path];
        if ($this->changeKind !== null) {
            $lineParts[] = match ($this->changeKind) {
                DriveWorkingCopyEntry::CHANGE_ADDED => 'new, uncommitted',
                DriveWorkingCopyEntry::CHANGE_DELETED => 'deleted, uncommitted',
                DriveWorkingCopyEntry::CHANGE_MOVED => 'moved from ' . $this->previousPath . ', uncommitted',
                default => 'changed, uncommitted',
            };
        }
        if ($this->conflictReason !== null) {
            $lineParts[] = 'CONFLICT: ' . $this->conflictReason;
        }
        return implode(' · ', $lineParts);
    }
}
