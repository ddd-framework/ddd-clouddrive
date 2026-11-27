<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\External;

use DDD\Domain\CloudDrive\Repo\DB\External\DBDriveExternalRendition;
use DDD\Domain\CloudDrive\Services\External\DriveExternalRenditionsService;
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
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The cached bytes of one external item at one revision in one format (the original, a PDF, a CSV, the structured
 * read as markdown), stored in our object store after the intake. Deleted with the space (disconnect deletes our
 * copies only).
 *
 * @property DriveExternalRenditions $parent
 * @method DriveExternalRenditions getParent()
 * @method static DriveExternalRenditionsService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['driveExternalItemId', 'providerRevision', 'format'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveExternalRendition::class)]
class DriveExternalRendition extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string FORMAT_ORIGINAL = 'ORIGINAL';
    public const string FORMAT_PDF = 'PDF';
    public const string FORMAT_CSV = 'CSV';
    public const string FORMAT_MARKDOWN = 'MARKDOWN';
    public const array FORMATS = [self::FORMAT_ORIGINAL, self::FORMAT_PDF, self::FORMAT_CSV, self::FORMAT_MARKDOWN];

    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveExternalItemId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?DriveExternalItem $driveExternalItem;

    #[Length(max: 128)]
    public ?string $providerRevision = null;

    #[Length(max: 16)]
    #[Choice(choices: self::FORMATS)]
    public ?string $format = self::FORMAT_ORIGINAL;

    #[Length(max: 255)]
    public ?string $contentType = null;

    #[Length(max: 64)]
    public ?string $sha256 = null;

    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $sizeBytes = null;

    #[Length(max: 512)]
    public ?string $storageKey = null;

    /** @var int|null The blob of the EXTERNAL space that holds the bytes (intake: type policy, malware scan) */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveBlobId = null;

    #[Length(max: 255)]
    public ?string $fileName = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(($this->driveExternalItemId ?? 0) . '_' . $this->providerRevision . '_' . $this->format);
    }
}
