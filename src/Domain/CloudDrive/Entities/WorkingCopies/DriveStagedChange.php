<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A change the runner saves after a call: the path, its kind, the version it was made on and, for new content, its
 * size and hashes. The answer says where to upload the bytes (or that the space knows them).
 */
class DriveStagedChange extends ValueObject
{
    public string $path = '';

    /** @var string One of DriveWorkingCopyEntry::CHANGES */
    public string $changeKind = DriveWorkingCopyEntry::CHANGE_MODIFIED;

    /** @var string|null MOVED: the path before */
    public ?string $previousPath = null;

    /** @var int|null The version the change was made on (from the base map); null for a new file */
    public ?int $baseVersionId = null;

    public ?string $sha256 = null;

    /** @var string|null MD5 of the content (base64): the store verifies the upload */
    public ?string $contentMd5 = null;

    public int $sizeBytes = 0;

    public ?string $contentType = null;

    public ?string $conversationKey = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->path);
    }
}
