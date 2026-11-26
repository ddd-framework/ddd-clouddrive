<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use ReflectionClass;

/** The nodes of the file source and import tests, in memory */
class FileImportDriveNodesService extends DriveNodesService
{
    /** @var DriveNode[] Nodes by id */
    public array $driveNodesById = [];

    public ?string $adminLinkTemplate = null;

    protected int $nextNodeId = 1000;

    public function __construct()
    {
    }

    public function addNode(int $driveSpaceId, ?int $parentNodeId, string $name, string $nodeType = DriveNode::NODE_TYPE_FOLDER, ?int $driveNodeId = null): DriveNode
    {
        /** @var DriveNode $driveNode */
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = $driveNodeId ?? $this->nextNodeId++;
        $driveNode->driveSpaceId = $driveSpaceId;
        $driveNode->parentNodeId = $parentNodeId;
        $driveNode->name = $name;
        $driveNode->nodeType = $nodeType;
        $driveNode->isSystem = false;
        $driveNode->isContentUntrusted = false;
        $driveNode->trashedAt = null;
        $driveNode->currentVersionId = null;
        $driveNode->latestVersionId = null;
        $driveNode->contentType = null;
        $driveNode->sizeBytes = null;
        $driveNode->sha256 = null;
        $driveNode->tags = null;
        $driveNode->description = null;
        $driveNode->versionNumber = null;
        $driveNode->childCount = 0;
        $this->driveNodesById[(int)$driveNode->id] = $driveNode;
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

    public function findOrCreateFolderPath(DriveSpace $driveSpace, string $path, ?int $actorAccountId = null, ?string $workingCopyKey = null): DriveNode
    {
        $currentNode = $this->findRootNode($driveSpace) ?? $this->addNode((int)$driveSpace->id, null, '');
        foreach (static::splitPath($path) as $pathSegment) {
            $currentNode = $this->findChildByName($currentNode, $pathSegment) ?? $this->addNode((int)$driveSpace->id, (int)$currentNode->id, $pathSegment);
        }
        return $currentNode;
    }

    public function buildPath(DriveNode $driveNode): string
    {
        $pathSegments = [];
        for ($currentNode = $driveNode; $currentNode !== null && $currentNode->parentNodeId !== null; $currentNode = $this->find($currentNode->parentNodeId)) {
            array_unshift($pathSegments, (string)$currentNode->name);
        }
        return implode('/', $pathSegments);
    }

    public function getAdminLinkTemplate(): ?string
    {
        return $this->adminLinkTemplate;
    }

    public function buildAdminLink(DriveNode $driveNode, ?string $path = null): ?string
    {
        return $this->adminLinkTemplate === null ? null : str_replace('{nodeId}', (string)$driveNode->id, $this->adminLinkTemplate);
    }
}
