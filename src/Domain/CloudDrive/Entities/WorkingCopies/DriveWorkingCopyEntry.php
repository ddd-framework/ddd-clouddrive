<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Repo\DB\WorkingCopies\DBDriveWorkingCopyEntry;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One saved, uncommitted change of a working copy: the path, the version it was made on (`baseVersionId`, null for a
 * new file) and the staged blob with the new content. A commit turns it into a version when the node's write head is
 * still the base (compare and swap) and deletes it.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveWorkingCopyEntry::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: ['driveWorkingCopyId', 'path'])]
class DriveWorkingCopyEntry extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveWorkingCopiesService::class;

    public const string CHANGE_ADDED = 'ADDED';
    public const string CHANGE_MODIFIED = 'MODIFIED';
    public const string CHANGE_DELETED = 'DELETED';
    public const string CHANGE_MOVED = 'MOVED';
    public const array CHANGES = [self::CHANGE_ADDED, self::CHANGE_MODIFIED, self::CHANGE_DELETED, self::CHANGE_MOVED];

    #[NotNull]
    public ?int $driveWorkingCopyId = null;

    #[NotNull]
    public ?int $driveSpaceId = null;

    /** @var string The path in the space after the change, e.g. `reports/Plan.md` */
    #[NotNull]
    #[Length(max: 1000)]
    #[DatabaseColumn(collation: DatabaseColumn::COLLATION_UTF8MB4_BIN)]
    public ?string $path = null;

    #[NotNull]
    #[Choice(choices: self::CHANGES)]
    #[Length(max: 16)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $changeKind = self::CHANGE_MODIFIED;

    /** @var string|null MOVED: the path before */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $previousPath = null;

    /** @var int|null The node the change is made on; null for a new file */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $driveNodeId = null;

    /** @var int|null The version the change was made on; null for a new file */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $baseVersionId = null;

    /** @var int|null The staged blob with the new content (ADDED, MODIFIED) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $stagedDriveBlobId = null;

    /** @var string|null Why the last commit left the change uncommitted (a newer version, the file was trashed, the path is taken) */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $conflictReason = null;

    /** @var int|null The newer version the last commit found ("theirs") */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $conflictVersionId = null;

    /** @var string|null The conversation whose turn made the change; its turn end commits it */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $conversationKey = null;

    public function hasConflict(): bool
    {
        return $this->conflictReason !== null && $this->conflictReason !== '';
    }
}
