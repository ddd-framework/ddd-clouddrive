<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\External;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\CloudDrive\Repo\DB\External\DBDriveExternalItem;
use DDD\Domain\CloudDrive\Services\External\DriveExternalItemsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Length;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;

/**
 * The metadata mirror of one item of an EXTERNAL space: an int64 surrogate id (the sandbox runner's node id) and a
 * surrogate version bumped whenever the provider's revision changes, the provider's ids and rights, the unique
 * filesystem name of its folder (the provider allows duplicate names; the display name stays the provider's).
 *
 * @property DriveExternalItems $parent
 * @method DriveExternalItems getParent()
 * @method static DriveExternalItemsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['driveSpaceId', 'providerItemId'])]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['driveSpaceId', 'parentProviderItemId', 'filesystemName'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveExternalItem::class)]
class DriveExternalItem extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveSpaceId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?DriveSpace $driveSpace;

    #[Length(max: 128)]
    public ?string $providerItemId = null;

    /** @var string|null The parent's provider id; null for the root of the space */
    #[Length(max: 128)]
    public ?string $parentProviderItemId = null;

    #[Length(max: 512)]
    public ?string $displayName = null;

    #[Length(max: 255)]
    public ?string $filesystemName = null;

    /** @var string|null The path below the space root, filesystem names joined by `/` */
    #[Length(max: 2048)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $path = null;

    #[Length(max: 255)]
    public ?string $mimeType = null;

    /** @var string|null FOLDER, FILE, GOOGLE_DOC, GOOGLE_SHEET, GOOGLE_SLIDES, GOOGLE_OTHER */
    #[Length(max: 16)]
    public ?string $kind = null;

    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $sizeBytes = null;

    #[Length(max: 64)]
    public ?string $md5Checksum = null;

    /** @var string|null The provider's revision (head revision of a binary, version of a native document) */
    #[Length(max: 128)]
    public ?string $providerRevision = null;

    /** @var int|null The runner's version id: bumped by every change of the provider's revision */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $surrogateVersion = 1;

    public ?bool $canEdit = false;

    public ?bool $canComment = false;

    public ?bool $canShare = false;

    public ?bool $canTrash = false;

    #[Length(max: 1024)]
    public ?string $webViewLink = null;

    public ?DateTime $modifiedAt = null;

    public ?DateTime $syncedAt = null;

    public function isFolder(): bool
    {
        return $this->kind === AgentConnectionDriveItem::KIND_FOLDER;
    }

    public function isNativeDocument(): bool
    {
        return in_array($this->kind, [AgentConnectionDriveItem::KIND_GOOGLE_DOC, AgentConnectionDriveItem::KIND_GOOGLE_SHEET, AgentConnectionDriveItem::KIND_GOOGLE_SLIDES], true);
    }

    public function buildReference(): string
    {
        return 'external/' . (int)$this->id;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->driveSpaceId ?? 0) . '_' . $this->providerItemId);
    }
}
