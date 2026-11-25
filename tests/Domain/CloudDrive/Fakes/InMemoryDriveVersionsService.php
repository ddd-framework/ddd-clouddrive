<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersions;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** The versions service on versions the test holds (no database) */
class InMemoryDriveVersionsService extends DriveVersionsService
{
    /** @var DriveFileVersion[] */
    public array $versions = [];

    public function __construct()
    {
    }

    public function addVersion(int $id, int $driveNodeId, int $versionNumber, ?int $copiedFromVersionId = null): DriveFileVersion
    {
        /** @var DriveFileVersion $driveFileVersion */
        $driveFileVersion = (new \ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $driveFileVersion->id = $id;
        $driveFileVersion->driveNodeId = $driveNodeId;
        $driveFileVersion->versionNumber = $versionNumber;
        $driveFileVersion->copiedFromVersionId = $copiedFromVersionId;
        $this->versions[] = $driveFileVersion;
        return $driveFileVersion;
    }

    public function findVersionsOfNode(DriveNode $driveNode, int $limit = 50): DriveFileVersions
    {
        $driveFileVersions = new DriveFileVersions();
        foreach ($this->versions as $driveFileVersion) {
            if ((int)$driveFileVersion->driveNodeId === (int)$driveNode->id) {
                $driveFileVersions->add($driveFileVersion);
            }
        }
        return $driveFileVersions;
    }

    public function findVersionsByIds(array $driveFileVersionIds): DriveFileVersions
    {
        $driveFileVersions = new DriveFileVersions();
        foreach ($this->versions as $driveFileVersion) {
            if (in_array((int)$driveFileVersion->id, array_map('intval', $driveFileVersionIds), true)) {
                $driveFileVersions->add($driveFileVersion);
            }
        }
        return $driveFileVersions;
    }
}
