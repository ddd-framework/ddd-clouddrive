<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use ReflectionClass;

/**
 * The agent drive store with one mount and at most one node of the test: the reading of a document is the real one,
 * the content grants, the blob store and the node paths are the test's.
 */
class DocumentReadingCloudDriveAgentDriveStoreService extends CloudDriveAgentDriveStoreService
{
    public ?DriveNode $driveNode = null;

    /** @var string[] The workspace paths committed before a read, in order */
    public array $committedPaths = [];

    public function __construct(
        public AgentDriveMount $agentDriveMount,
        public FixedDeliveryDriveContentGrantsService $fixedDeliveryDriveContentGrantsService,
        public FixedStorageDriveBlobsService $fixedStorageDriveBlobsService,
        public FixedPathDriveNodesService $fixedPathDriveNodesService
    ) {
    }

    public function findNodeOfPath(DriveSpace $driveSpace, AgentDrivePath $agentDrivePath): ?DriveNode
    {
        return $this->driveNode;
    }

    public function requireMountOfNode(AgentDriveMounts $agentDriveMounts, AgentDrivePath $agentDrivePath, DriveNode $driveNode): AgentDriveMount
    {
        return $this->agentDriveMount;
    }

    protected function requireMount(AgentDrivePath $agentDrivePath): AgentDriveMount
    {
        return $this->agentDriveMount;
    }

    protected function requireSpaceOfMount(AgentDriveMount $agentDriveMount): DriveSpace
    {
        /** @var DriveSpace $driveSpace */
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->id = (int)$agentDriveMount->spaceKey;
        return $driveSpace;
    }

    protected function commitUncommittedSource(AgentToolCallProvenance $callProvenance, AgentDriveMount $sourceMount, DriveSpace $sourceSpace, AgentDrivePath $sourcePath): ?string
    {
        $this->committedPaths[] = $sourcePath->getWorkspacePath();
        return null;
    }

    protected function findMountsOfReference(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath): AgentDriveMounts
    {
        return new AgentDriveMounts();
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        return $this->fixedDeliveryDriveContentGrantsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return $this->fixedStorageDriveBlobsService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->fixedPathDriveNodesService;
    }
}
