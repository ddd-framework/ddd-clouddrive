<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Blobs;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Repo\DB\Blobs\DBDriveBlob;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The bytes of one or more versions of a space: one object in the store under a key of its own, written once and never
 * overwritten. Its state says what may read it: UPLOADING (the bytes are on their way), STAGED (held by a working copy,
 * not yet a version), PENDING (a version waits for its scan), RELEASED (people may read it), BLOCKED (the scan
 * refused it), FAILED, PURGING (the sweep removes it). Equal content of one space shares a released blob.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveBlob::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: ['storageKey'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveSpaceId', 'sha256'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['state', 'expiresAt'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveSpaceId', 'idempotencyKey'])]
class DriveBlob extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveBlobsService::class;

    public const string STATE_UPLOADING = 'UPLOADING';
    public const string STATE_STAGED = 'STAGED';
    public const string STATE_PENDING = 'PENDING';
    public const string STATE_RELEASED = 'RELEASED';
    public const string STATE_BLOCKED = 'BLOCKED';
    public const string STATE_FAILED = 'FAILED';
    public const string STATE_PURGING = 'PURGING';
    public const array STATES = [
        self::STATE_UPLOADING, self::STATE_STAGED, self::STATE_PENDING, self::STATE_RELEASED,
        self::STATE_BLOCKED, self::STATE_FAILED, self::STATE_PURGING,
    ];

    #[NotNull]
    public ?int $driveSpaceId = null;

    /** @var string The object's key in the store: `<spaceId>/<blob id>-<random>`, never reused */
    #[NotNull]
    #[Length(max: 255)]
    public ?string $storageKey = null;

    #[NotNull]
    #[Choice(choices: self::STATES)]
    #[Length(max: 16)]
    public ?string $state = self::STATE_UPLOADING;

    /** @var int The size the upload announced; after completion the size the store reported */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $sizeBytes = 0;

    /** @var string|null SHA-256 (hex) computed by the server from the stored bytes, or announced by a working copy */
    #[Length(max: 64)]
    public ?string $sha256 = null;

    /** @var string|null MD5 (base64) the store verifies on upload */
    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $contentMd5 = null;

    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $contentType = null;

    /** @var string|null The multipart upload of the store, while one runs */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $multipartUploadId = null;

    /** @var string|null The client's key of one upload: a retried reserve gets the same blob */
    #[Length(max: 128)]
    public ?string $idempotencyKey = null;

    /** @var string|null Why the scan blocked it, or the failure */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $stateReason = null;

    /** @var DateTime|null An unfinished upload or an unreferenced staged blob is swept after this */
    public ?DateTime $expiresAt = null;

    public function isReadableByPeople(): bool
    {
        return $this->state === self::STATE_RELEASED;
    }
}
