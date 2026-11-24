<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces\DBAgentSpaceDriveSpaces;
use DDD\Domain\CloudDrive\Services\AI\AgentSpaceDriveSpacesService;

/**
 * @property AgentSpaceDriveSpace[] $elements
 * @method AgentSpaceDriveSpace|null getByUniqueKey(string $uniqueKey)
 * @method AgentSpaceDriveSpace[] getElements()
 * @method AgentSpaceDriveSpace|null first()
 * @method static AgentSpaceDriveSpacesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentSpaceDriveSpaces::class)]
class AgentSpaceDriveSpaces extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentSpaceDriveSpacesService::class;
}
