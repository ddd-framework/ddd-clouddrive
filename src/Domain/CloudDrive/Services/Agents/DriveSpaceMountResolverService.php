<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\CloudDrive\Entities\AI\Mounts\DriveSpaceMount;
use DDD\Domain\CloudDrive\Entities\AI\Mounts\DriveSpaceMounts;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Which spaces the sandbox of an agent space works on: its own drive (the workspace itself) and the further spaces
 * mounted below `drivespaces/`. A host overrides this service at the same path: it knows which space belongs to an
 * agent space (its anchor column) and which spaces every operator allowed on the agent space may read.
 *
 * The default: no own drive, and every library space (`spaceKind` library) the caller may read, read-only.
 */
class DriveSpaceMountResolverService extends Service
{
    public const string LIBRARY_SPACE_KIND = 'library';

    /** The agent space's own drive space, found or created; null when the host gives agent spaces no drive */
    public function findOrCreateOwnDriveSpace(AgentSpace $agentSpace): ?DriveSpace
    {
        return null;
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
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        $repoClass = $driveSpacesService->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.spaceKind = :spaceKind")->setParameter('spaceKind', self::LIBRARY_SPACE_KIND)
            ->orderBy("{$alias}.id", 'ASC')->setMaxResults(20);
        $driveSpaces = $repoClass->find($queryBuilder, false);
        return $driveSpaces instanceof DriveSpaces ? $driveSpaces : new DriveSpaces();
    }
}
