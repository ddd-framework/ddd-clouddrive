<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\CloudDrive\Entities\AI\Mounts\DriveSpaceMount;
use DDD\Domain\CloudDrive\Entities\AI\Mounts\DriveSpaceMounts;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Which spaces the sandbox of an agent space works on: its own drive (the workspace itself) and the further spaces
 * mounted below `drivespaces/`. A host overrides this service at the same path: it knows which space belongs to an
 * agent space (its anchor column) and which spaces every operator allowed on the agent space may read.
 *
 * The default: one own drive per agent space (`spaceKind` agentSpace, owned by the agent space's account), created on
 * first use and linked in `AgentSpaceDriveSpaces`; every library space (`spaceKind` library) the caller may read is
 * mounted read-only. Who may read and write the own drive is the host's space rights; without a rule of the host the
 * agent space's owner holds every right on it ({@see AgentOwnDriveSpaceRightsExtensionService}).
 */
class DriveSpaceMountResolverService extends Service
{
    public const string LIBRARY_SPACE_KIND = 'library';

    /** @var string The kind of an agent space's own drive (its defaults in `CloudDrive.spaceKinds`) */
    public const string OWN_DRIVE_SPACE_KIND = 'agentSpace';

    /** @var int Seconds the creation of an own drive holds its lock */
    public const int OWN_DRIVE_CREATION_LOCK_SECONDS = 30;

    /** @var int How often a caller that waits for another's creation looks again (every 200 ms) */
    public const int OWN_DRIVE_CREATION_WAIT_ATTEMPTS = 25;

    /**
     * The agent space's own drive space, found or created with its link; null when the agent space is not stored, the
     * link table is missing (schema not applied yet) or a host gives agent spaces no drive (override returning null).
     */
    public function findOrCreateOwnDriveSpace(AgentSpace $agentSpace): ?DriveSpace
    {
        $agentSpaceId = isset($agentSpace->id) && (int)$agentSpace->id > 0 ? (int)$agentSpace->id : null;
        if ($agentSpaceId === null) {
            return null;
        }
        $ownDriveSpace = $this->findOwnDriveSpace($agentSpace);
        if ($ownDriveSpace !== null || !$this->getAgentSpaceDriveSpacesService()->isTableAvailable()) {
            return $ownDriveSpace;
        }
        $lockKey = 'clouddrive_own_drive_' . $agentSpaceId;
        $cache = null;
        $lockToken = null;
        try {
            $cache = Cache::instance(Cache::CACHE_GROUP_REDIS);
            $lockToken = $cache->acquireLock($lockKey, self::OWN_DRIVE_CREATION_LOCK_SECONDS);
        } catch (Throwable $cacheException) {
            // no cache: the unique link decides between concurrent creations
            $cache = null;
            DDDService::instance()->getLogger()->info('[CloudDrive] own drive created without a lock', ['exception' => $cacheException->getMessage()]);
        }
        if ($cache !== null && $lockToken === null) {
            // another call creates it: wait for its link
            for ($attempt = 0; $attempt < self::OWN_DRIVE_CREATION_WAIT_ATTEMPTS; $attempt++) {
                usleep(200000);
                $ownDriveSpace = $this->findOwnDriveSpace($agentSpace);
                if ($ownDriveSpace !== null) {
                    return $ownDriveSpace;
                }
            }
            return null;
        }
        try {
            return $this->findOwnDriveSpace($agentSpace) ?? $this->createOwnDriveSpace($agentSpace, $agentSpaceId);
        } finally {
            if ($cache !== null && $lockToken !== null) {
                try {
                    $cache->releaseLock($lockKey, $lockToken);
                } catch (Throwable) {
                    // the lock expires with its TTL
                }
            }
        }
    }

    /** The agent space's own drive space when it exists; never creates one (rights are the caller's concern) */
    public function findOwnDriveSpace(AgentSpace $agentSpace): ?DriveSpace
    {
        $agentSpaceId = isset($agentSpace->id) && (int)$agentSpace->id > 0 ? (int)$agentSpace->id : null;
        if ($agentSpaceId === null) {
            return null;
        }
        $driveSpaceId = $this->getAgentSpaceDriveSpacesService()->findDriveSpaceIdOfAgentSpaceId($agentSpaceId);
        if ($driveSpaceId === null) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveSpace = $this->getDriveSpacesService()->find($driveSpaceId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $driveSpace instanceof DriveSpace ? $driveSpace : null;
    }

    /** A new own drive (staff audience, owned by the agent space's account) and its link */
    protected function createOwnDriveSpace(AgentSpace $agentSpace, int $agentSpaceId): DriveSpace
    {
        $driveSpace = DriveSpace::newInstance();
        $driveSpace->spaceKind = static::OWN_DRIVE_SPACE_KIND;
        $driveSpace->audience = DriveSpace::AUDIENCE_INTERNAL;
        $driveSpace->title = $this->buildOwnDriveSpaceTitle($agentSpace, $agentSpaceId);
        $driveSpace->accountId = $agentSpace->accountId;
        $driveSpace = $this->getDriveSpacesService()->createSpace($driveSpace);
        try {
            $this->getAgentSpaceDriveSpacesService()->linkOwnDriveSpace($agentSpaceId, (int)$driveSpace->id);
        } catch (Throwable $linkException) {
            // a concurrent call linked its space first: use that one; the empty space just created stays unlinked
            $linkedDriveSpace = $this->findOwnDriveSpace($agentSpace);
            if ($linkedDriveSpace === null) {
                throw $linkException;
            }
            DDDService::instance()->getLogger()->warning('[CloudDrive] own drive created twice; the second stays unlinked', ['agentSpaceId' => $agentSpaceId, 'unlinkedDriveSpaceId' => $driveSpace->id]);
            return $linkedDriveSpace;
        }
        return $driveSpace;
    }

    /** The title of a new own drive; a host names it after its own record */
    protected function buildOwnDriveSpaceTitle(AgentSpace $agentSpace, int $agentSpaceId): string
    {
        return 'Agent space ' . $agentSpaceId;
    }

    /**
     * The working copy key of the call's sandbox on the own drive: the call's workspace key, else null (the store then
     * takes the newest working copy of the own drive). A host whose workspace keys follow a rule overrides this.
     */
    public function findWorkingCopyKeyOfCall(AgentToolCallProvenance $callProvenance): ?string
    {
        return $callProvenance->workspaceKey !== null && $callProvenance->workspaceKey !== '' ? $callProvenance->workspaceKey : null;
    }

    /**
     * The audience the agent acts as for a call ({@see DriveSpacesService::getCurrentAudience()}): INTERNAL (the
     * staff) by default. A host whose agent works for a partner on shared spaces answers SHARED: the partner's write
     * lock, type policy and content variants then apply to the drive tools.
     */
    public function findAgentAudienceOfCall(AgentToolCallProvenance $callProvenance): string
    {
        return DriveSpace::AUDIENCE_INTERNAL;
    }

    /** The further spaces the sandbox mounts: only spaces every operator allowed on the agent space may read */
    public function findMountsOfAgentSpace(AgentSpace $agentSpace): DriveSpaceMounts
    {
        $driveSpaceMounts = new DriveSpaceMounts();
        foreach ($this->findLibrarySpaces()->getElements() as $librarySpace) {
            $driveSpaceMount = new DriveSpaceMount();
            $driveSpaceMount->driveSpace = $librarySpace;
            $driveSpaceMount->mountName = $librarySpace->buildMountName();
            $driveSpaceMount->mode = AgentDriveMount::MODE_READ_ONLY;
            $driveSpaceMounts->add($driveSpaceMount);
        }
        return $driveSpaceMounts;
    }

    /** The library spaces the caller may read */
    protected function findLibrarySpaces(): DriveSpaces
    {
        $repoClass = $this->getDriveSpacesService()->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.spaceKind = :spaceKind")->setParameter('spaceKind', self::LIBRARY_SPACE_KIND)
            ->orderBy("{$alias}.id", 'ASC')->setMaxResults(20);
        $driveSpaces = $repoClass->find($queryBuilder, false);
        return $driveSpaces instanceof DriveSpaces ? $driveSpaces : new DriveSpaces();
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getAgentSpaceDriveSpacesService(): AgentSpaceDriveSpacesService
    {
        /** @var AgentSpaceDriveSpacesService $agentSpaceDriveSpacesService */
        $agentSpaceDriveSpacesService = DDDService::instance()->getService(AgentSpaceDriveSpacesService::class);
        return $agentSpaceDriveSpacesService;
    }
}
