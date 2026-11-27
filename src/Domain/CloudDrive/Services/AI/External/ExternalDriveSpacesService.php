<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI\External;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Throwable;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoot;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Libs\Connections\AgentConnectionDriveReach;
use DDD\Domain\AI\Listeners\Connections\AgentConnectionDriveRootsChangedListener;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\GoogleDocumentsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAdaptersService;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveReleasesService;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveScopeService;
use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalSpace;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalSpaces;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * EXTERNAL drive spaces: one space (spaceKind externalDrive, bound by a DriveExternalSpace row) per ACTIVE root of a drive connection (AgentConnections capability FILES). The space
 * is authorized only through its connection's access check (owner, or a release to a space the account belongs to,
 * the host's membership hook; fail closed) and the roots of that reach, never through the drive's own space rights.
 * When the roots of a connection change, its spaces are rebuilt: a new root gets a space, a removed root's space is
 * deleted with its binding, mirror and renditions (our copies only; the provider's items are never touched).
 */
class ExternalDriveSpacesService extends Service implements AgentConnectionDriveRootsChangedListener
{
    /** @var int no quota of ours: the provider keeps the content; renditions count against this ceiling only */
    public const int EXTERNAL_QUOTA_BYTES = 1099511627776;

    public const int EXTERNAL_MAXIMUM_FILE_BYTES = 104857600;

    public function afterDriveRootsChanged(AgentConnection $agentConnection): void
    {
        $this->syncSpacesOfConnection($agentConnection);
    }

    /** One space per ACTIVE root; spaces of roots that went are deleted (cascade: binding, mirror, renditions) */
    public function syncSpacesOfConnection(AgentConnection $agentConnection): DriveSpaces
    {
        /** @var AgentConnectionDriveReleasesService $agentConnectionDriveReleasesService */
        $agentConnectionDriveReleasesService = DDDService::instance()->getService(AgentConnectionDriveReleasesService::class);
        $rootNamesById = [];
        foreach ($agentConnectionDriveReleasesService->findRootsOfConnection($agentConnection)->getElements() as $agentConnectionDriveRoot) {
            if ($agentConnectionDriveRoot->status === AgentConnectionDriveRoot::STATUS_ACTIVE) {
                $rootNamesById[(string)$agentConnectionDriveRoot->itemId] = (string)$agentConnectionDriveRoot->itemName;
            }
        }
        foreach ($agentConnection->getScopeBoundaryValues(AgentConnectionDriveScopeService::BOUNDARY_KIND_FOLDER) as $folderId) {
            $rootNamesById[$folderId] ??= $folderId;
        }
        $isUsable = $agentConnection->isUsable();
        $keptSpaces = DriveSpaces::newInstance();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach ($this->findBindingsOfConnection((int)$agentConnection->id)->getElements() as $driveExternalSpace) {
                $rootId = (string)$driveExternalSpace->externalRootItemId;
                $driveSpace = $this->getDriveSpacesService()->find((int)$driveExternalSpace->driveSpaceId, false);
                if (!$driveSpace instanceof DriveSpace) {
                    $driveExternalSpace->delete();
                    continue;
                }
                if (!$isUsable || !isset($rootNamesById[$rootId])) {
                    $driveSpace->delete();
                    continue;
                }
                $driveSpace->title = $this->buildSpaceTitle($agentConnection, $rootNamesById[$rootId], count($rootNamesById));
                $driveSpace->updatePartialIgnoringRights('title');
                $keptSpaces->add($driveSpace);
                unset($rootNamesById[$rootId]);
            }
            if ($isUsable) {
                foreach ($rootNamesById as $rootId => $rootName) {
                    $spaceTitle = $this->buildSpaceTitle($agentConnection, (string)$rootName, count($rootNamesById) + $keptSpaces->count());
                    $keptSpaces->add($this->createBoundSpace($agentConnection, (string)$rootId, $spaceTitle));
                }
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $keptSpaces;
    }

    /**
     * A new EXTERNAL space and its binding in one transaction: a space without its binding would be an internal space
     * nobody reaches, a binding without its space points nowhere. Called with the rights restrictions switched off.
     */
    protected function createBoundSpace(AgentConnection $agentConnection, string $rootId, string $spaceTitle): DriveSpace
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $connection->beginTransaction();
        try {
            $driveSpace = DriveSpace::newInstance();
            $driveSpace->spaceKind = DriveSpace::EXTERNAL_SPACE_KIND;
            $driveSpace->audience = DriveSpace::AUDIENCE_INTERNAL;
            $driveSpace->title = $spaceTitle;
            $driveSpace->quotaBytes = static::EXTERNAL_QUOTA_BYTES;
            $driveSpace->maxFileSizeBytes = static::EXTERNAL_MAXIMUM_FILE_BYTES;
            /** @var DriveSpace $storedSpace */
            $storedSpace = $driveSpace->update();
            $driveExternalSpace = DriveExternalSpace::newInstance();
            $driveExternalSpace->driveSpaceId = (int)$storedSpace->id;
            $driveExternalSpace->agentConnectionId = (int)$agentConnection->id;
            $driveExternalSpace->externalRootItemId = $rootId;
            $driveExternalSpace->update();
            $connection->commit();
        } catch (Throwable $throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
        return $storedSpace;
    }

    /**
     * The EXTERNAL spaces the account reaches with a reach (a call: its space and conversation): its usable drive
     * connections there (own connections where nobody else reads, releases to that space), their roots of that reach. Nothing while AgentConnections is off.
     */
    public function findReachableSpacesOfAccount(int $accountId, AgentConnectionReach $agentConnectionReach): DriveSpaces
    {
        $reachableSpaces = DriveSpaces::newInstance();
        if (!AgentConnectionsConfiguration::current()->isEnabled()) {
            return $reachableSpaces;
        }
        $agentConnectionsService = $this->getAgentConnectionsService();
        foreach ($agentConnectionsService->findUsableConnectionsOfAccount($accountId, $agentConnectionReach)->getElements() as $agentConnection) {
            $allowedRootIds = $this->findAllowedRootIds($agentConnection, $accountId, $agentConnectionReach);
            if ($allowedRootIds === null) {
                continue;
            }
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                foreach ($this->findBindingsOfConnection((int)$agentConnection->id)->getElements() as $driveExternalSpace) {
                    if (!in_array((string)$driveExternalSpace->externalRootItemId, $allowedRootIds, true)) {
                        continue;
                    }
                    $driveSpace = $this->getDriveSpacesService()->find((int)$driveExternalSpace->driveSpaceId, false);
                    if ($driveSpace instanceof DriveSpace) {
                        $reachableSpaces->add($driveSpace);
                    }
                }
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
        return $reachableSpaces;
    }

    /**
     * The reach of an EXTERNAL space for an account in an agent space: its connection, the account's access (owner,
     * or a release to that agent space), the adapters. Refused when AgentConnections is off, the connection is not
     * usable, the account does not reach it there, its FILES level is OFF, or the space's root is outside the roots
     * of that reach.
     *
     * @throws ForbiddenException
     */
    public function requireReachOfSpace(DriveSpace $driveSpace, int $accountId, ?AgentToolCallProvenance $callProvenance): AgentConnectionDriveReach
    {
        if (!AgentConnectionsConfiguration::current()->isEnabled()) {
            throw new ForbiddenException('External drives are not available.');
        }
        $driveExternalSpace = $driveSpace->isExternal() ? $this->findBindingOfSpace((int)$driveSpace->id) : null;
        if ($driveExternalSpace === null) {
            throw new ForbiddenException('This space is no external drive.');
        }
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentConnection = $agentConnectionsService->findIgnoringRights((int)$driveExternalSpace->agentConnectionId);
        if (!$agentConnection instanceof AgentConnection || !$agentConnection->isUsable()) {
            throw new ForbiddenException('The drive connection of this space is not usable (disconnected, expired or paused).');
        }
        $agentConnectionReach = AgentConnectionReach::ofCall($callProvenance);
        $allowedRootIds = $this->findAllowedRootIds($agentConnection, $accountId, $agentConnectionReach);
        if ($allowedRootIds === null) {
            throw new ForbiddenException('This external drive is not released for you here.');
        }
        if (!in_array((string)$driveExternalSpace->externalRootItemId, $allowedRootIds, true)) {
            throw new ForbiddenException('This folder of the external drive is not released for you.');
        }
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $adapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        if (!$adapter instanceof DriveAgentConnectionAdapter || !$adapter instanceof GoogleDocumentsAgentConnectionAdapter) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" is no drive.");
        }
        $agentConnectionAccess = $agentConnectionsService->getAccessOfAccount($agentConnection, $accountId, $agentConnectionReach, AgentConnectionPermission::CAPABILITY_FILES);
        if ($agentConnectionAccess === null) {
            throw new ForbiddenException('This external drive is not released for you here.');
        }
        return new AgentConnectionDriveReach($agentConnection, $agentConnectionAccess, $adapter, $adapter, $callProvenance);
    }

    /** The provider's root of an EXTERNAL space (null for a space of our own) */
    public function findRootItemIdOfSpace(DriveSpace $driveSpace): ?string
    {
        if (!$driveSpace->isExternal()) {
            return null;
        }
        $driveExternalSpace = $this->findBindingOfSpace((int)$driveSpace->id);
        return $driveExternalSpace?->externalRootItemId;
    }

    /** The mount name of a space: the connection's name (and the root's when the connection has several), filesystem-safe */
    public function buildMountName(DriveSpace $driveSpace): string
    {
        $mountName = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$driveSpace->title) ?? '';
        $mountName = trim($mountName, '-.');
        return ($mountName !== '' ? mb_substr($mountName, 0, 60) : 'external') . '-' . (int)$driveSpace->id;
    }

    public function findBindingsOfConnection(int $agentConnectionId): DriveExternalSpaces
    {
        $repository = DriveExternalSpaces::getRepoClassInstance();
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("{$alias}.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', $agentConnectionId);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveExternalSpaces = $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $driveExternalSpaces instanceof DriveExternalSpaces ? $driveExternalSpaces : DriveExternalSpaces::newInstance();
    }

    public function findBindingOfSpace(int $driveSpaceId): ?DriveExternalSpace
    {
        $repository = DriveExternalSpaces::getRepoClassInstance();
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")->setParameter('driveSpaceId', $driveSpaceId)->setMaxResults(1);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveExternalSpaces = $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $driveExternalSpace = $driveExternalSpaces?->first();
        return $driveExternalSpace instanceof DriveExternalSpace ? $driveExternalSpace : null;
    }

    /**
     * The roots of the connection's FILES reach for the account in that reach; null when the account does not reach
     * the connection there or its FILES level is OFF.
     *
     * @return string[]|null
     */
    protected function findAllowedRootIds(AgentConnection $agentConnection, int $accountId, AgentConnectionReach $agentConnectionReach): ?array
    {
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentConnectionAccess = $agentConnectionsService->getAccessOfAccount($agentConnection, $accountId, $agentConnectionReach, AgentConnectionPermission::CAPABILITY_FILES);
        if ($agentConnectionAccess === null
            || $agentConnectionsService->getEffectiveLevel($agentConnection, AgentConnectionPermission::CAPABILITY_FILES, $accountId, $agentConnectionReach) === AgentConnectionPermission::LEVEL_OFF) {
            return null;
        }
        return $agentConnectionAccess->getAllowedBoundaryValues($agentConnection, AgentConnectionDriveScopeService::BOUNDARY_KIND_FOLDER) ?? [];
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function buildSpaceTitle(AgentConnection $agentConnection, string $rootName, int $rootCount): string
    {
        $connectionName = $agentConnection->getLabel();
        return mb_substr($rootCount > 1 ? $connectionName . ' · ' . $rootName : $connectionName, 0, 255);
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        return $agentConnectionsService;
    }
}
