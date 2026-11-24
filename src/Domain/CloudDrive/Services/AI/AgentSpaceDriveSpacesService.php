<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces\AgentSpaceDriveSpace;
use DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces\AgentSpaceDriveSpaces;
use DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces\DBAgentSpaceDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces\DBAgentSpaceDriveSpaces;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The own drives of agent spaces (table `AgentSpaceDriveSpaces`). Reads and writes switch the rights off: which
 * space belongs to an agent space is the resolver's decision, not the caller's right.
 *
 * Before the table exists (code before schema) every lookup answers "no own drive" and {@see isTableAvailable()} is
 * false, so the resolver creates no space it could not link.
 *
 * @method AgentSpaceDriveSpace|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentSpaceDriveSpaces findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentSpaceDriveSpace getEntityRepoClassInstance()
 * @method DBAgentSpaceDriveSpaces getEntitySetRepoClassInstance()
 */
class AgentSpaceDriveSpacesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = AgentSpaceDriveSpace::class;

    /** @var bool Whether the table answered in this process (a missing table is asked again: the schema may come) */
    protected bool $isTableAvailable = false;

    /** @var bool Whether a missing table was logged in this process */
    protected bool $isMissingTableLogged = false;

    /** The drive space id of the agent space's own drive; null when it has none yet or the table is missing */
    public function findDriveSpaceIdOfAgentSpaceId(int $agentSpaceId): ?int
    {
        $agentSpaceDriveSpace = $this->findRowByColumn('agentSpaceId', $agentSpaceId);
        return $agentSpaceDriveSpace !== null ? (int)$agentSpaceDriveSpace->driveSpaceId : null;
    }

    /** The agent space whose own drive the space is; null for any other space */
    public function findAgentSpaceIdOfDriveSpaceId(int $driveSpaceId): ?int
    {
        $agentSpaceDriveSpace = $this->findRowByColumn('driveSpaceId', $driveSpaceId);
        return $agentSpaceDriveSpace !== null ? (int)$agentSpaceDriveSpace->agentSpaceId : null;
    }

    /** Links the space as the agent space's own drive (a strict insert: a second link of either side throws) */
    public function linkOwnDriveSpace(int $agentSpaceId, int $driveSpaceId): AgentSpaceDriveSpace
    {
        $agentSpaceDriveSpace = AgentSpaceDriveSpace::newInstance();
        $agentSpaceDriveSpace->agentSpaceId = $agentSpaceId;
        $agentSpaceDriveSpace->driveSpaceId = $driveSpaceId;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AgentSpaceDriveSpace $agentSpaceDriveSpace */
            $agentSpaceDriveSpace = $agentSpaceDriveSpace->insert();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentSpaceDriveSpace;
    }

    /** Whether the table exists (a found table is remembered for the process; a missing one is logged once) */
    public function isTableAvailable(): bool
    {
        if (!$this->isTableAvailable) {
            $this->findRowByColumn('agentSpaceId', 0);
        }
        return $this->isTableAvailable;
    }

    protected function findRowByColumn(string $columnName, int $columnValue): ?AgentSpaceDriveSpace
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.{$columnName} = :columnValue")->setParameter('columnValue', $columnValue)->setMaxResults(1);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpaceDriveSpaces = $repoClass->find($queryBuilder, false);
            $this->isTableAvailable = true;
        } catch (Throwable $missingTableException) {
            $this->isTableAvailable = false;
            if ($this->isMissingTableLogged) {
                return null;
            }
            $this->isMissingTableLogged = true;
            DDDService::instance()->getLogger()->warning('[CloudDrive] the table AgentSpaceDriveSpaces cannot be read (schema not applied?): agent spaces get no own drive', ['exception' => $missingTableException->getMessage()]);
            return null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $agentSpaceDriveSpace = $agentSpaceDriveSpaces instanceof AgentSpaceDriveSpaces ? $agentSpaceDriveSpaces->first() : null;
        return $agentSpaceDriveSpace instanceof AgentSpaceDriveSpace ? $agentSpaceDriveSpace : null;
    }
}
