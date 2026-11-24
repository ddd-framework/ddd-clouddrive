<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces\DBAgentSpaceDriveSpace;
use DDD\Domain\CloudDrive\Services\AI\AgentSpaceDriveSpacesService;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The own drive of an agent space: one row per agent space and per drive space, created with the space at its first
 * use ({@see \DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService::findOrCreateOwnDriveSpace()}). A
 * deleted agent space or an erased drive space drops its row.
 *
 * @method static AgentSpaceDriveSpacesService getService()
 * @method static DBAgentSpaceDriveSpace getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentSpaceDriveSpace::class)]
class AgentSpaceDriveSpace extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = AgentSpaceDriveSpacesService::class;

    /** @var int The agent space whose own drive this is */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public ?int $agentSpaceId = null;

    /** @var AgentSpace|null The agent space as the host's entity: never serialized, never part of the API schema */
    #[LazyLoad]
    #[HideProperty]
    #[Ignore]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?AgentSpace $agentSpace;

    /** @var int The drive space */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public ?int $driveSpaceId = null;

    /** @var DriveSpace|null The drive space */
    #[LazyLoad]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?DriveSpace $driveSpace;
}
