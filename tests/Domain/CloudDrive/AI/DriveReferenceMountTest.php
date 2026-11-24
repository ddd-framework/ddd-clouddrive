<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ScratchAwareCloudDriveAgentDriveStoreService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ScratchKeyDriveSpacesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A reference (`drive/<id>`) names a node of any space and is resolved against the own drive: the write rules of the
 * node's own mount apply, so a read-only or consent-gated space cannot be written through a reference.
 */
class DriveReferenceMountTest extends TestCase
{
    public function testAReferenceTakesTheMountOfItsNodesSpaceNotTheOwnDrive(): void
    {
        $ownDriveMount = self::buildMount('7', '', AgentDriveMount::MODE_READ_WRITE, true);
        $sharedMount = self::buildMount('12', 'space-12', AgentDriveMount::MODE_READ_ONLY, false);
        $agentDriveMounts = new AgentDriveMounts();
        $agentDriveMounts->add($ownDriveMount);
        $agentDriveMounts->add($sharedMount);
        $agentDrivePath = new AgentDrivePath();
        $agentDrivePath->argument = 'drive/812';
        $agentDrivePath->reference = 'drive/812';
        $agentDrivePath->agentDriveMount = $ownDriveMount;
        $resolvedMount = self::buildStore()->requireMountOfNode($agentDriveMounts, $agentDrivePath, self::buildNode(812, 12));
        self::assertSame($sharedMount, $resolvedMount);
        self::assertSame(AgentDriveMount::MODE_READ_ONLY, $resolvedMount->getEffectiveWriteMode());
    }

    public function testAPathKeepsTheMountItWasResolvedIn(): void
    {
        $sharedMount = self::buildMount('12', 'space-12', AgentDriveMount::MODE_READ_ONLY, false);
        $agentDrivePath = new AgentDrivePath();
        $agentDrivePath->argument = 'drivespaces/space-12/Logo.svg';
        $agentDrivePath->pathInMount = 'Logo.svg';
        $agentDrivePath->agentDriveMount = $sharedMount;
        self::assertSame($sharedMount, self::buildStore()->requireMountOfNode(new AgentDriveMounts(), $agentDrivePath, self::buildNode(812, 12)));
    }

    public function testAReferenceToANodeOfNoMountedSpaceIsNotFound(): void
    {
        $agentDriveMounts = new AgentDriveMounts();
        $ownDriveMount = self::buildMount('7', '', AgentDriveMount::MODE_READ_WRITE, true);
        $agentDriveMounts->add($ownDriveMount);
        $agentDrivePath = new AgentDrivePath();
        $agentDrivePath->argument = 'drive/900';
        $agentDrivePath->reference = 'drive/900';
        $this->expectException(NotFoundException::class);
        self::buildStore()->requireMountOfNode($agentDriveMounts, $agentDrivePath, self::buildNode(900, 99));
    }

    public function testACallOnAScratchDriveIsRecognised(): void
    {
        $scratchKeyDriveSpacesService = new ScratchKeyDriveSpacesService();
        /** @var DriveSpace $scratchDriveSpace */
        $scratchDriveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $scratchDriveSpace->id = 5;
        $scratchKeyDriveSpacesService->scratchDriveSpacesByKey['eval-case-1'] = $scratchDriveSpace;
        $scratchAwareStore = new ScratchAwareCloudDriveAgentDriveStoreService($scratchKeyDriveSpacesService);
        $callProvenance = (new ReflectionClass(AgentToolCallProvenance::class))->newInstanceWithoutConstructor();
        $callProvenance->workspaceKey = 'eval-case-1';
        self::assertTrue($scratchAwareStore->isScratchDriveCall($callProvenance));
        $callProvenance->workspaceKey = 'space-42';
        self::assertFalse($scratchAwareStore->isScratchDriveCall($callProvenance));
        $callProvenance->workspaceKey = null;
        self::assertFalse($scratchAwareStore->isScratchDriveCall($callProvenance));
    }

    protected static function buildStore(): CloudDriveAgentDriveStoreService
    {
        return (new ReflectionClass(CloudDriveAgentDriveStoreService::class))->newInstanceWithoutConstructor();
    }

    protected static function buildMount(string $spaceKey, string $mountName, string $mode, bool $isOwnDrive): AgentDriveMount
    {
        $agentDriveMount = new AgentDriveMount();
        $agentDriveMount->storeName = CloudDriveAgentDriveStoreService::STORE_NAME;
        $agentDriveMount->spaceKey = $spaceKey;
        $agentDriveMount->mountName = $mountName;
        $agentDriveMount->mode = $mode;
        $agentDriveMount->isOwnDrive = $isOwnDrive;
        return $agentDriveMount;
    }

    protected static function buildNode(int $driveNodeId, int $driveSpaceId): DriveNode
    {
        /** @var DriveNode $driveNode */
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = $driveNodeId;
        $driveNode->driveSpaceId = $driveSpaceId;
        return $driveNode;
    }
}
