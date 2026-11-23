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
    public int $id = 0;

    public int $driveNodeId = 0;

    public int $versionNumber = 0;

    public ?int $sizeBytes = null;

    public ?string $contentType = null;

    public ?string $comment = null;

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
