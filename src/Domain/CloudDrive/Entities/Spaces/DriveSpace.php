<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One drive: a tree of folders and versioned files with one audience, fixed at creation. A host extends the class at
 * the same path with typed anchor columns (an agent space, a customer) and its repository answers who may read,
 * write and manage a space ({@see DBDriveSpace}); every drive table is filtered through it. A space without an anchor
 * is a library. `spaceKind` selects the defaults of `CloudDrive.spaceKinds` and decides no right.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveSpace::class)]
class DriveSpace extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = DriveSpacesService::class;

    public const string AUDIENCE_INTERNAL = 'INTERNAL';
    public const string AUDIENCE_SHARED = 'SHARED';

    public const string DEFAULT_SPACE_KIND = 'default';

    /** @var int|null Optional owner; decides no right and may change */
    public ?int $accountId = null;

    #[LazyLoad(addAsParent: true)]
    public ?Account $account;

    /** @var string Selects the defaults of `CloudDrive.spaceKinds` (quota, largest file, retention) */
    #[NotNull]
    #[Length(max: 64)]
    public ?string $spaceKind = self::DEFAULT_SPACE_KIND;

    /** @var string Who besides the staff sees the space; fixed at creation */
    #[NotNull]
    #[Choice(choices: [self::AUDIENCE_INTERNAL, self::AUDIENCE_SHARED])]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $audience = self::AUDIENCE_INTERNAL;

    /** @var string The space's name for people */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $title = null;

    /** @var int|null The root folder; created with the space */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $rootNodeId = null;

    /** @var int|null Override of the kind's quota in bytes (versions and trash count) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $quotaBytes = null;

    /** @var int|null Override of the kind's largest file in bytes */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $maxFileSizeBytes = null;

    /** @var int Bytes used and reserved: versions, trash and running uploads */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $usedBytes = 0;

    /** @var int Live files (not trashed) */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $fileCount = 0;

    /** @var int Live folders, the root excluded */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $folderCount = 0;

    /** @var bool The audience reads only; the staff still writes */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $isAudienceWriteLocked = false;

    public function isShared(): bool
    {
        return $this->audience === self::AUDIENCE_SHARED;
    }

    /** One setting of the space's kind, with the `default` kind and the module's default as fallbacks */
    public function getSpaceKindSetting(string $settingName, mixed $defaultValue = null): mixed
    {
        $spaceKinds = Config::get('CloudDrive.spaceKinds');
        $spaceKinds = is_array($spaceKinds) ? $spaceKinds : [];
        return $spaceKinds[$this->spaceKind ?? self::DEFAULT_SPACE_KIND][$settingName]
            ?? $spaceKinds[self::DEFAULT_SPACE_KIND][$settingName]
            ?? $defaultValue;
    }

    public function getEffectiveQuotaBytes(): int
    {
        return $this->quotaBytes ?? (int)$this->getSpaceKindSetting('quotaBytes', 5 * 1024 * 1024 * 1024);
    }

    public function getEffectiveMaxFileSizeBytes(): int
    {
        return $this->maxFileSizeBytes ?? (int)$this->getSpaceKindSetting('maxFileSizeBytes', 250 * 1024 * 1024);
    }

    public function getKeptVersionCount(): int
    {
        return (int)$this->getSpaceKindSetting('keptVersions', 20);
    }

    public function getVersionRetentionDays(): int
    {
        return (int)$this->getSpaceKindSetting('versionRetentionDays', 90);
    }

    public function getTrashRetentionDays(): int
    {
        return (int)$this->getSpaceKindSetting('trashRetentionDays', 30);
    }

    /** The name a mount shows for the space, e.g. `library` or `space-12` */
    public function buildMountName(): string
    {
        return $this->spaceKind === 'library' ? 'library' : 'space-' . $this->id;
    }

    public function buildDisplayTitle(): string
    {
        return $this->title !== null && $this->title !== '' ? $this->title : ($this->spaceKind . ' ' . $this->id);
    }
}
