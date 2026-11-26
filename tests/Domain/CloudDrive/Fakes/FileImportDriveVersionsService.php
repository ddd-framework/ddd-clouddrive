<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWrite;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWriteResult;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use ReflectionClass;

/** Writes of the import tests: a file node per write, every write and ingest recorded */
class FileImportDriveVersionsService extends DriveVersionsService
{
    /** @var DriveFileWrite[] The writes by file name */
    public array $driveFileWritesByName = [];

    /** @var int[] The ids of the versions handed to the ingest */
    public array $ingestedVersionIds = [];

    public bool $answersConflict = false;

    protected int $nextVersionId = 500;

    public function __construct(public FileImportDriveNodesService $fileImportDriveNodesService)
    {
    }

    public function writeFile(DriveSpace $driveSpace, DriveNode $folderNode, string $name, DriveFileWrite $driveFileWrite): DriveFileWriteResult
    {
        $driveFileWriteResult = new DriveFileWriteResult();
        if ($this->answersConflict) {
            $driveFileWriteResult->isConflict = true;
            $driveFileWriteResult->conflictReason = 'the file has a newer version';
            return $driveFileWriteResult;
        }
        $this->driveFileWritesByName[$name] = $driveFileWrite;
        $driveNode = $this->fileImportDriveNodesService->findChildByName($folderNode, $name);
        $driveFileWriteResult->isNewFile = $driveNode === null;
        $driveNode ??= $this->fileImportDriveNodesService->addNode((int)$driveSpace->id, (int)$folderNode->id, $name, DriveNode::NODE_TYPE_FILE);
        /** @var DriveFileVersion $driveFileVersion */
        $driveFileVersion = (new ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $driveFileVersion->id = $this->nextVersionId++;
        $driveFileVersion->driveNodeId = (int)$driveNode->id;
        $driveNode->latestVersionId = (int)$driveFileVersion->id;
        $driveNode->currentVersionId = (int)$driveFileVersion->id;
        $driveNode->origin = $driveFileWrite->origin;
        $driveNode->isContentUntrusted = $driveFileWrite->isContentUntrusted;
        $driveFileWriteResult->driveNode = $driveNode;
        $driveFileWriteResult->driveFileVersion = $driveFileVersion;
        return $driveFileWriteResult;
    }

    public function ingestVersion(DriveFileVersion $driveFileVersion, bool $isScanned = true, ?string $workingCopyKey = null, ?bool $async = null): void
    {
        $this->ingestedVersionIds[] = (int)$driveFileVersion->id;
    }

    public function find(int|string|null $entityId, bool $useEntityRegistrCache = true): ?DriveFileVersion
    {
        return null;
    }
}
