<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Versions;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Repo\DB\Versions\DBDriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One version of a file: its blob, the comment (a commit message), author and origin. A version is never changed
 * after it was written; its blob's state decides whether people can read it (RELEASED) or only its working copy.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveFileVersion::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: ['driveNodeId', 'versionNumber'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveSpaceId', 'driveBlobId'])]
class DriveFileVersion extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveVersionsService::class;

    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveSpaceId = null;

    #[NotNull]
    public ?int $driveNodeId = null;

    #[LazyLoad(addAsParent: true)]
    public ?DriveNode $driveNode;

    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $versionNumber = 1;

    /** @var int The blob with the content; a version keeps it (no foreign key: dedup re-points versions, the sweep deletes blobs) */
    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveBlobId = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $sizeBytes = null;

    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $contentType = null;

    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $sha256 = null;

    /** @var string|null The version comment (commit message) */
    #[Length(max: 2000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $comment = null;

    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $origin = DriveNode::ORIGIN_UPLOAD;

    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $isContentUntrusted = false;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $authorAccountId = null;

    /** @var int|null The account an agent wrote this version for */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $onBehalfOfAccountId = null;

    /** @var string|null The conversation whose agent wrote it */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $conversationKey = null;

    /** @var int|null The version this one was copied from (another space possibly) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $copiedFromVersionId = null;
}
