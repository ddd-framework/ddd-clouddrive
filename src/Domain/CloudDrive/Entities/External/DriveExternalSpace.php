<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\External;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\External\DBDriveExternalSpace;
use DDD\Domain\CloudDrive\Services\External\DriveExternalSpacesService;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The binding of an EXTERNAL drive space (spaceKind {@see DriveSpace::EXTERNAL_SPACE_KIND}) to the drive connection
 * and the provider's root it shows. Kept in its own table so the drive's own table carries no column of the
 * connection feature.
 *
 * @property DriveExternalSpaces $parent
 * @method DriveExternalSpaces getParent()
 * @method static DriveExternalSpacesService getService()
 */
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['driveSpaceId'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentConnectionId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBDriveExternalSpace::class)]
class DriveExternalSpace extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $driveSpaceId = null;

    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?DriveSpace $driveSpace;

    /** @var int|null The drive connection (AgentConnection id) whose root the space shows */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $agentConnectionId = null;

    /** @var string|null The provider's id of the root (folder or shared drive) */
    #[Length(max: 128)]
    public ?string $externalRootItemId = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)($this->driveSpaceId ?? 0));
    }
}
