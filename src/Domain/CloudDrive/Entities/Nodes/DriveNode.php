<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNode;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A folder, a file or an in-space link of a drive space. Names are case-sensitive and unique per folder among live
 * nodes: the unique key is (`driveSpaceId`, `parentNodeId`, `nameSlot`), where `nameSlot` is the name of a live node
 * and `/trashed/<id>` of a trashed one (a name never holds a slash). `nameFolded` (lower case, no accents) serves search only. A file's
 * `latestVersionId` is the write head (pending versions included), `currentVersionId` the released read head.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveNode::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: ['driveSpaceId', 'parentNodeId', 'nameSlot'], nullsNotDistinct: true)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveSpaceId', 'nameFolded'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveSpaceId', 'trashedAt'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['driveSpaceId', 'expiresAt'])]
class DriveNode extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveNodesService::class;

    public const string NODE_TYPE_FOLDER = 'FOLDER';
    public const string NODE_TYPE_FILE = 'FILE';
    public const string NODE_TYPE_LINK = 'LINK';

    public const string ORIGIN_UPLOAD = 'UPLOAD';
    public const string ORIGIN_AGENT = 'AGENT';
    public const string ORIGIN_COPY = 'COPY';
    public const string ORIGIN_MIGRATED = 'MIGRATED';

    /** @var string The prefix of the name slot of a trashed node; a name never contains a slash */
    public const string TRASHED_NAME_SLOT_PREFIX = "/trashed/";

    public const int MAX_NAME_LENGTH = 255;

    #[NotNull]
    public ?int $driveSpaceId = null;

    #[LazyLoad(addAsParent: true)]
    public ?DriveSpace $driveSpace;

    /** @var int|null The folder; null only for the space's root folder */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $parentNodeId = null;

    #[NotNull]
    #[Choice(choices: [self::NODE_TYPE_FOLDER, self::NODE_TYPE_FILE, self::NODE_TYPE_LINK])]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $nodeType = self::NODE_TYPE_FILE;

    /** @var string The name, case-sensitive; '' for the root folder */
    #[NotNull]
    #[Length(max: self::MAX_NAME_LENGTH)]
    #[DatabaseColumn(collation: DatabaseColumn::COLLATION_UTF8MB4_BIN)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $name = '';

    /** @var string The unique slot of the name: the name while live, `/trashed/<id>` in the trash */
    #[NotNull]
    #[Length(max: 300)]
    #[DatabaseColumn(collation: DatabaseColumn::COLLATION_UTF8MB4_BIN)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $nameSlot = '';

    /** @var string The name in lower case without accents, for search; never an address */
    #[Length(max: self::MAX_NAME_LENGTH)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $nameFolded = '';

    /** @var int|null LINK: the node in the same space it points to */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $linkTargetNodeId = null;

    /** @var int|null The released version people read */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $currentVersionId = null;

    /** @var int|null The newest written version, pending included: the base a write compares and swaps */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $latestVersionId = null;

    /** @var int|null The newest version's number */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $versionNumber = null;

    /** @var int|null The newest version's size */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $sizeBytes = null;

    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $contentType = null;

    /** @var string|null The newest version's SHA-256 (hex) */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $sha256 = null;

    /** @var string|null Tags, lower case, wrapped in commas (`,report,q3,`) so `LIKE '%,q3,%'` finds one */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $tags = null;

    #[Length(max: 2000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $description = null;

    /** @var bool A system folder (`work/`): hidden in the screen, not scanned or indexed */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $isSystem = false;

    /** @var bool The content was written by a third party (a shared space, a web page) */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $isContentUntrusted = false;

    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $origin = self::ORIGIN_UPLOAD;

    /** @var int|null The node this one was copied from (another space possibly); no sync afterwards */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $copiedFromNodeId = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $copiedFromVersionId = null;

    /** @var int Live children of a folder */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $childCount = 0;

    public ?DateTime $expiresAt = null;

    public ?DateTime $trashedAt = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $trashedByAccountId = null;

    /** @var int|null The node whose trashing took this one along (a folder); restored with it */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $trashedWithNodeId = null;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $createdByAccountId = null;

    /** @return string[] */
    public function getTagList(): array
    {
        return array_values(array_filter(explode(',', (string)$this->tags), 'strlen'));
    }

    /** @param string[] $tagList */
    public function setTagList(array $tagList): void
    {
        $normalizedTags = [];
        foreach ($tagList as $tag) {
            $normalizedTag = str_replace(',', ' ', mb_strtolower(trim((string)$tag, " #\t\n")));
            if ($normalizedTag !== '' && mb_strlen($normalizedTag) <= 64) {
                $normalizedTags[$normalizedTag] = $normalizedTag;
            }
        }
        $this->tags = $normalizedTags === [] ? null : ',' . implode(',', array_values($normalizedTags)) . ',';
    }

    public function isFolder(): bool
    {
        return $this->nodeType === self::NODE_TYPE_FOLDER;
    }

    public function isFile(): bool
    {
        return $this->nodeType === self::NODE_TYPE_FILE;
    }

    public function isTrashed(): bool
    {
        return isset($this->trashedAt);
    }

    public function isRoot(): bool
    {
        return $this->parentNodeId === null;
    }

    /** The reference handed to tools and cards */
    public function buildReference(): string
    {
        return 'drive/' . $this->id;
    }

    public static function buildTrashedNameSlot(int $nodeId): string
    {
        return self::TRASHED_NAME_SLOT_PREFIX . $nodeId;
    }

    /** Lower case, accents removed, whitespace folded */
    public static function foldName(string $name): string
    {
        $foldedName = mb_strtolower($name);
        $transliteratedName = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII', $foldedName)
            : @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $foldedName);
        $foldedName = is_string($transliteratedName) && $transliteratedName !== '' ? $transliteratedName : $foldedName;
        return trim((string)preg_replace('/\s+/', ' ', $foldedName));
    }

    /**
     * The name as given, or a reason it cannot be one: empty, `.`/`..`, a slash, a control character, too long.
     */
    public static function findInvalidNameReason(string $name): ?string
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return 'a name cannot be empty, "." or ".."';
        }
        if (str_contains($name, '/') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return 'a name cannot contain "/" or control characters';
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return 'a name has at most ' . self::MAX_NAME_LENGTH . ' characters';
        }
        return null;
    }
}
