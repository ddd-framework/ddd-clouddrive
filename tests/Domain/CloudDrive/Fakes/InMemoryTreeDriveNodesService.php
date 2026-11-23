<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItem;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use ReflectionClass;

/** Folders and files of several spaces in memory, for the location resolution */
class InMemoryTreeDriveNodesService extends DriveNodesService
{
    /** @var DriveNode[] Nodes by id */
    public array $driveNodesById = [];

    public function __construct(public MountNamingDriveSpacesService $mountNamingDriveSpacesService)
    {
    }

    public function addNode(int $driveNodeId, int $driveSpaceId, ?int $parentNodeId, string $name, string $nodeType = DriveNode::NODE_TYPE_FOLDER): DriveNode
    {
        /** @var DriveNode $driveNode */
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = $driveNodeId;
        $driveNode->driveSpaceId = $driveSpaceId;
        $driveNode->parentNodeId = $parentNodeId;
        $driveNode->name = $name;
        $driveNode->nodeType = $nodeType;
        $this->driveNodesById[$driveNodeId] = $driveNode;
        return $driveNode;
    }

    public function find(int|string|null $entityId, bool $useEntityRegistrCache = true): ?DriveNode
    {
        return $this->driveNodesById[(int)$entityId] ?? null;
    }

    public function findRootNode(DriveSpace $driveSpace): ?DriveNode
    {
        foreach ($this->driveNodesById as $driveNode) {
            if ((int)$driveNode->driveSpaceId === (int)$driveSpace->id && $driveNode->parentNodeId === null) {
                return $driveNode;
            }
        }
        return null;
    }

    public function findChildByName(DriveNode $folderNode, string $name): ?DriveNode
    {
        foreach ($this->driveNodesById as $driveNode) {
            if ($driveNode->parentNodeId === (int)$folderNode->id && $driveNode->name === $name && !$driveNode->isTrashed()) {
                return $driveNode;
            }
        }
        return null;
    }

    public function buildPath(DriveNode $driveNode): string
    {
        $pathSegments = [];
        for ($currentNode = $driveNode; $currentNode !== null && $currentNode->parentNodeId !== null; $currentNode = $this->find($currentNode->parentNodeId)) {
            array_unshift($pathSegments, (string)$currentNode->name);
        }
        return implode('/', $pathSegments);
    }

    public function buildListItemOfNode(DriveNode $driveNode, DriveSpace $driveSpace): ?DriveNodeListItem
    {
        $driveNodeListItem = new DriveNodeListItem();
        $driveNodeListItem->id = (int)$driveNode->id;
        $driveNodeListItem->driveSpaceId = (int)$driveSpace->id;
        $driveNodeListItem->name = (string)$driveNode->name;
        return $driveNodeListItem;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->mountNamingDriveSpacesService;
    }
}
