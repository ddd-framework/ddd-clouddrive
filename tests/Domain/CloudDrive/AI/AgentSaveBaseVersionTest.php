<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\AI;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\InMemoryDriveVersionsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\InMemoryVersionsCloudDriveAgentDriveStoreService;
use PHPUnit\Framework\TestCase;

/**
 * The agent's direct save (saveDriveFile) knows which version of the target its copy was made from: a target someone
 * else wrote since is refused, never written over.
 */
class AgentSaveBaseVersionTest extends TestCase
{
    protected const int TARGET_NODE_ID = 10;
    protected const int SOURCE_NODE_ID = 20;

    public function testACopyMadeFromTheNewestVersionIsWritten(): void
    {
        $versions = new InMemoryDriveVersionsService();
        $versions->addVersion(103, self::TARGET_NODE_ID, 3);
        $store = new InMemoryVersionsCloudDriveAgentDriveStoreService($versions);
        $sourceNode = $this->buildNode(self::SOURCE_NODE_ID, null, self::TARGET_NODE_ID, 103);
        self::assertNull($store->findStaleBaseRefusal($sourceNode, $this->buildNode(self::TARGET_NODE_ID, 103)));
    }

    public function testACopyMadeFromAnOlderVersionIsRefusedWhenSomeoneElseWroteSince(): void
    {
        $versions = new InMemoryDriveVersionsService();
        $versions->addVersion(103, self::TARGET_NODE_ID, 3);
        // version 4: a person's edit in the canvas (no copy origin)
        $versions->addVersion(104, self::TARGET_NODE_ID, 4);
        $store = new InMemoryVersionsCloudDriveAgentDriveStoreService($versions);
        $sourceNode = $this->buildNode(self::SOURCE_NODE_ID, null, self::TARGET_NODE_ID, 103);
        $refusal = $store->findStaleBaseRefusal($sourceNode, $this->buildNode(self::TARGET_NODE_ID, 104));
        self::assertNotNull($refusal);
        self::assertStringContainsString('newer version (4)', $refusal);
        self::assertStringContainsString('nothing was written', $refusal);
    }

    public function testTheAgentsOwnLastSaveIsNoConflict(): void
    {
        $versions = new InMemoryDriveVersionsService();
        $versions->addVersion(103, self::TARGET_NODE_ID, 3);
        $versions->addVersion(201, self::SOURCE_NODE_ID, 1, 103);
        $versions->addVersion(202, self::SOURCE_NODE_ID, 2);
        // the target's version 4 was written from the source's version 2: the agent's own save
        $versions->addVersion(104, self::TARGET_NODE_ID, 4, 202);
        $store = new InMemoryVersionsCloudDriveAgentDriveStoreService($versions);
        $sourceNode = $this->buildNode(self::SOURCE_NODE_ID, 202, self::TARGET_NODE_ID, 103);
        self::assertNull($store->findStaleBaseRefusal($sourceNode, $this->buildNode(self::TARGET_NODE_ID, 104)));
    }

    public function testCopyingTheTargetOntoTheFileMakesItsNewestVersionTheBase(): void
    {
        $versions = new InMemoryDriveVersionsService();
        $versions->addVersion(103, self::TARGET_NODE_ID, 3);
        $versions->addVersion(104, self::TARGET_NODE_ID, 4);
        $versions->addVersion(201, self::SOURCE_NODE_ID, 1, 103);
        // the agent took the target's version 4 into its file, then merged
        $versions->addVersion(202, self::SOURCE_NODE_ID, 2, 104);
        $versions->addVersion(203, self::SOURCE_NODE_ID, 3);
        $store = new InMemoryVersionsCloudDriveAgentDriveStoreService($versions);
        $sourceNode = $this->buildNode(self::SOURCE_NODE_ID, 203, self::TARGET_NODE_ID, 103);
        self::assertNull($store->findStaleBaseRefusal($sourceNode, $this->buildNode(self::TARGET_NODE_ID, 104)));
    }

    public function testAFileThatNeverMetTheTargetHasNoBaseAndIsWrittenOnTheNewestVersion(): void
    {
        $versions = new InMemoryDriveVersionsService();
        $versions->addVersion(103, self::TARGET_NODE_ID, 3);
        $versions->addVersion(201, self::SOURCE_NODE_ID, 1);
        $store = new InMemoryVersionsCloudDriveAgentDriveStoreService($versions);
        self::assertNull($store->findStaleBaseRefusal($this->buildNode(self::SOURCE_NODE_ID, 201), $this->buildNode(self::TARGET_NODE_ID, 103)));
        // a copy of another file is no base of this target either
        $otherOrigin = $this->buildNode(self::SOURCE_NODE_ID, 201, 77, 7001);
        self::assertNull($store->findStaleBaseRefusal($otherOrigin, $this->buildNode(self::TARGET_NODE_ID, 103)));
    }

    public function testATargetWithoutAVersionAndTheFileItselfAreNeverRefused(): void
    {
        $store = new InMemoryVersionsCloudDriveAgentDriveStoreService(new InMemoryDriveVersionsService());
        self::assertNull($store->findStaleBaseRefusal($this->buildNode(self::SOURCE_NODE_ID, 201, self::TARGET_NODE_ID, 103), $this->buildNode(self::TARGET_NODE_ID, null)));
        $sameNode = $this->buildNode(self::TARGET_NODE_ID, 103);
        self::assertNull($store->findStaleBaseRefusal($sameNode, $sameNode));
    }

    protected function buildNode(int $id, ?int $latestVersionId, ?int $copiedFromNodeId = null, ?int $copiedFromVersionId = null): DriveNode
    {
        /** @var DriveNode $driveNode */
        $driveNode = (new \ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = $id;
        $driveNode->driveSpaceId = 1;
        $driveNode->latestVersionId = $latestVersionId;
        $driveNode->versionNumber = null;
        $driveNode->copiedFromNodeId = $copiedFromNodeId;
        $driveNode->copiedFromVersionId = $copiedFromVersionId;
        return $driveNode;
    }
}
