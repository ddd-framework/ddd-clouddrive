<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeCounts;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNode;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNodes;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;

/**
 * Folders, files and links of a space: lookup by path, listing, creating folders, renaming, moving, tags and
 * descriptions, trash and restore. Paths are `/`-separated names below the space's root, e.g. `reports/Plan.md`.
 * Every read runs under the space rights of the caller; writes check the write right of the space first.
 *
 * @method DriveNode|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DriveNodes findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDriveNode getEntityRepoClassInstance()
 * @method DBDriveNodes getEntitySetRepoClassInstance()
 */
class DriveNodesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DriveNode::class;

    public const int MAX_PATH_DEPTH = 64;
    public const int MAX_LIST_LIMIT = 1000;

    /** @var string The cursor of a listing: the last name of the previous page */
    public const string LIST_CURSOR_PREFIX = 'n:';

    /** The root folder of the space */
    public function findRootNode(DriveSpace $driveSpace): ?DriveNode
    {
        if ($driveSpace->rootNodeId !== null) {
            $rootNode = $this->findNodeOfSpace($driveSpace, (int)$driveSpace->rootNodeId);
            if ($rootNode !== null) {
                return $rootNode;
            }
        }
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")->andWhere("{$alias}.parentNodeId IS NULL")
            ->setParameter('driveSpaceId', (int)$driveSpace->id);
        $rootNode = $repoClass->find($queryBuilder, false);
        return $rootNode instanceof DriveNode ? $rootNode : null;
    }

    /** A node of the space by id (trashed ones too), under the caller's rights */
    public function findNodeOfSpace(DriveSpace $driveSpace, int $driveNodeId): ?DriveNode
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.id = :driveNodeId")->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->setParameter('driveNodeId', $driveNodeId)->setParameter('driveSpaceId', (int)$driveSpace->id);
        $driveNode = $repoClass->find($queryBuilder, false);
        return $driveNode instanceof DriveNode ? $driveNode : null;
    }

    /** The live child of a folder with exactly this name (case-sensitive) */
    public function findChildByName(DriveNode $folderNode, string $name): ?DriveNode
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.parentNodeId = :parentNodeId")
            ->andWhere("{$alias}.nameSlot = :nameSlot")
            ->andWhere("{$alias}.trashedAt IS NULL")
            ->setParameter('driveSpaceId', (int)$folderNode->driveSpaceId)
            ->setParameter('parentNodeId', (int)$folderNode->id)
            ->setParameter('nameSlot', $name);
        $driveNode = $repoClass->find($queryBuilder, false);
        // the comparison of the database may fold case or accents: the name decides
        return $driveNode instanceof DriveNode && $driveNode->name === $name ? $driveNode : null;
    }

    /**
     * The live node at a path below the root ('' = the root); null when a segment is missing.
     *
     * @throws BadRequestException
     */
    public function findNodeByPath(DriveSpace $driveSpace, string $path): ?DriveNode
    {
        $currentNode = $this->findRootNode($driveSpace);
        foreach (static::splitPath($path) as $pathSegment) {
            if ($currentNode === null || !$currentNode->isFolder()) {
                return null;
            }
            $currentNode = $this->findChildByName($currentNode, $pathSegment);
        }
        return $currentNode;
    }

    /**
     * The folder at the path, created with every missing parent.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     */
    public function findOrCreateFolderPath(DriveSpace $driveSpace, string $path, ?int $actorAccountId = null, ?string $workingCopyKey = null): DriveNode
    {
        $currentNode = $this->findRootNode($driveSpace);
        if ($currentNode === null) {
            throw new NotFoundException(sprintf('Drive space %d has no root folder.', (int)$driveSpace->id));
        }
        foreach (static::splitPath($path) as $pathSegment) {
            $childNode = $this->findChildByName($currentNode, $pathSegment);
            if ($childNode === null) {
                $childNode = $this->createFolder($driveSpace, $currentNode, $pathSegment, $actorAccountId, $workingCopyKey);
            } elseif (!$childNode->isFolder()) {
                throw new BadRequestException(sprintf('"%s" is a file, not a folder.', $this->buildPath($childNode)));
            }
            $currentNode = $childNode;
        }
        return $currentNode;
    }

    /**
     * A new folder in a folder.
     *
     * @throws BadRequestException
     */
    public function createFolder(DriveSpace $driveSpace, DriveNode $parentNode, string $name, ?int $actorAccountId = null, ?string $workingCopyKey = null): DriveNode
    {
        $invalidNameReason = DriveNode::findInvalidNameReason($name);
        if ($invalidNameReason !== null) {
            throw new BadRequestException(sprintf('"%s": %s.', $name, $invalidNameReason));
        }
        if ($this->findChildByName($parentNode, $name) !== null) {
            throw new BadRequestException(sprintf('"%s" exists already in "%s".', $name, $this->buildPath($parentNode)));
        }
        $folderNode = DriveNode::newInstance();
        $folderNode->driveSpaceId = (int)$driveSpace->id;
        $folderNode->parentNodeId = (int)$parentNode->id;
        $folderNode->nodeType = DriveNode::NODE_TYPE_FOLDER;
        $folderNode->name = $name;
        $folderNode->nameSlot = $name;
        $folderNode->nameFolded = DriveNode::foldName($name);
        $folderNode->createdByAccountId = $actorAccountId ?? $this->findActingAccountId();
        $folderNode->isSystem = $parentNode->isRoot() && $this->isSystemFolderName($name) ? true : (bool)$parentNode->isSystem;
        /** @var DriveNode $folderNode */
        $folderNode = $folderNode->update();
        $this->adjustChildCount((int)$parentNode->id, 1);
        $this->getDriveSpacesService()->adjustCounters((int)$driveSpace->id, 0, 1);
        $this->getDriveChangesService()->recordChange($folderNode, DriveChangeEvent::EVENT_CREATED, path: $this->buildPath($folderNode), workingCopyKey: $workingCopyKey);
        return $folderNode;
    }

    /**
     * One page of a folder's live children: folders first, then files, by name; `$cursor` continues after the last
     * name of the previous page. System folders are left out unless `$includeSystem`.
     */
    public function findChildren(DriveNode $folderNode, ?string $cursor = null, int $limit = 100, bool $includeSystem = true): DriveNodes
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.parentNodeId = :parentNodeId")
            ->andWhere("{$alias}.trashedAt IS NULL")
            ->setParameter('driveSpaceId', (int)$folderNode->driveSpaceId)
            ->setParameter('parentNodeId', (int)$folderNode->id)
            ->orderBy("{$alias}.nodeType", 'ASC')
            ->addOrderBy("{$alias}.nameSlot", 'ASC')
            ->setMaxResults(max(1, min(self::MAX_LIST_LIMIT, $limit)));
        if (!$includeSystem) {
            $queryBuilder->andWhere("{$alias}.isSystem = :isSystem")->setParameter('isSystem', false);
        }
        $cursorParts = $cursor !== null && str_starts_with($cursor, self::LIST_CURSOR_PREFIX)
            ? explode("\t", substr($cursor, strlen(self::LIST_CURSOR_PREFIX)), 2) : null;
        if ($cursorParts !== null && count($cursorParts) === 2) {
            $queryBuilder->andWhere("({$alias}.nodeType > :cursorNodeType OR ({$alias}.nodeType = :cursorNodeType AND {$alias}.nameSlot > :cursorName))")
                ->setParameter('cursorNodeType', $cursorParts[0])
                ->setParameter('cursorName', $cursorParts[1]);
        }
        $driveNodes = $repoClass->find($queryBuilder, false);
        return $driveNodes instanceof DriveNodes ? $driveNodes : new DriveNodes();
    }

    /** The cursor that continues a listing after this node */
    public static function buildListCursor(DriveNode $driveNode): string
    {
        return self::LIST_CURSOR_PREFIX . $driveNode->nodeType . "\t" . $driveNode->nameSlot;
    }

    /**
     * The path of a node below its space's root, e.g. `reports/Plan.md` ('' for the root).
     */
    public function buildPath(DriveNode $driveNode): string
    {
        $pathSegments = [];
        $currentNode = $driveNode;
        $depth = 0;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            while ($currentNode !== null && !$currentNode->isRoot() && $depth < self::MAX_PATH_DEPTH) {
                array_unshift($pathSegments, (string)$currentNode->name);
                $parentNode = $this->find((int)$currentNode->parentNodeId);
                $currentNode = $parentNode instanceof DriveNode ? $parentNode : null;
                $depth++;
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return implode('/', $pathSegments);
    }

    /**
     * Renames and/or moves a node inside its space. A folder is never moved into itself or one of its descendants.
     *
     * @throws BadRequestException
     */
    public function moveNode(DriveNode $driveNode, ?DriveNode $targetFolderNode, ?string $newName, ?string $workingCopyKey = null): DriveNode
    {
        $this->requireWriteRight($driveNode);
        if ($driveNode->isRoot()) {
            throw new BadRequestException('The root folder cannot be moved or renamed.');
        }
        $previousPath = $this->buildPath($driveNode);
        $targetFolderNode ??= $this->find((int)$driveNode->parentNodeId, false);
        if (!$targetFolderNode instanceof DriveNode || !$targetFolderNode->isFolder() || $targetFolderNode->driveSpaceId !== $driveNode->driveSpaceId) {
            throw new BadRequestException('The target is no folder of the same space.');
        }
        if ($driveNode->isFolder() && $this->isNodeOrAncestor($driveNode, $targetFolderNode)) {
            throw new BadRequestException('A folder cannot be moved into itself.');
        }
        $newName ??= (string)$driveNode->name;
        $invalidNameReason = DriveNode::findInvalidNameReason($newName);
        if ($invalidNameReason !== null) {
            throw new BadRequestException(sprintf('"%s": %s.', $newName, $invalidNameReason));
        }
        $existingNode = $this->findChildByName($targetFolderNode, $newName);
        if ($existingNode !== null && $existingNode->id !== $driveNode->id) {
            throw new BadRequestException(sprintf('"%s" exists already in "%s".', $newName, $this->buildPath($targetFolderNode)));
        }
        $previousParentNodeId = (int)$driveNode->parentNodeId;
        $driveNode->parentNodeId = (int)$targetFolderNode->id;
        $driveNode->name = $newName;
        $driveNode->nameSlot = $newName;
        $driveNode->nameFolded = DriveNode::foldName($newName);
        $driveNode->updatePartialIgnoringRights('parentNodeId', 'name', 'nameSlot', 'nameFolded');
        if ($previousParentNodeId !== (int)$targetFolderNode->id) {
            $this->adjustChildCount($previousParentNodeId, -1);
            $this->adjustChildCount((int)$targetFolderNode->id, 1);
        }
        $eventType = $previousParentNodeId === (int)$targetFolderNode->id ? DriveChangeEvent::EVENT_RENAMED : DriveChangeEvent::EVENT_MOVED;
        $this->getDriveChangesService()->recordChange($driveNode, $eventType, path: $this->buildPath($driveNode), previousPath: $previousPath, workingCopyKey: $workingCopyKey);
        return $driveNode;
    }

    /**
     * Replaces tags and/or the description of a node.
     *
     * @param string[]|null $tags
     */
    public function updateMetadata(DriveNode $driveNode, ?array $tags, ?string $description): DriveNode
    {
        $this->requireWriteRight($driveNode);
        $changedProperties = [];
        if ($tags !== null) {
            $driveNode->setTagList($tags);
            $changedProperties[] = 'tags';
        }
        if ($description !== null) {
            $driveNode->description = trim($description) !== '' ? mb_substr(trim($description), 0, 2000) : null;
            $changedProperties[] = 'description';
        }
        if ($changedProperties !== []) {
            $driveNode->updatePartialIgnoringRights(...$changedProperties);
            $this->getDriveChangesService()->recordChange($driveNode, DriveChangeEvent::EVENT_METADATA, path: $this->buildPath($driveNode));
        }
        return $driveNode;
    }

    /**
     * Moves a node to the trash; a folder takes its live descendants along (restored with it). The name slot frees.
     *
     * @throws BadRequestException
     */
    public function trashNode(DriveNode $driveNode, ?string $workingCopyKey = null): DriveNode
    {
        $this->requireWriteRight($driveNode);
        if ($driveNode->isRoot()) {
            throw new BadRequestException('The root folder cannot be trashed.');
        }
        if ($driveNode->isTrashed()) {
            return $driveNode;
        }
        $path = $this->buildPath($driveNode);
        $trashedCounts = new DriveNodeCounts();
        $this->markTrashed($driveNode, null, $trashedCounts);
        $this->adjustChildCount((int)$driveNode->parentNodeId, -1);
        $this->getDriveSpacesService()->adjustCounters((int)$driveNode->driveSpaceId, -$trashedCounts->fileCount, -$trashedCounts->folderCount);
        $this->getDriveChangesService()->recordChange($driveNode, DriveChangeEvent::EVENT_TRASHED, path: $path, workingCopyKey: $workingCopyKey);
        return $driveNode;
    }

    /**
     * Restores a trashed node (and what was trashed with it) to its folder; under a new name when the old one is taken,
     * and to the root when its folder is gone.
     *
     * @throws BadRequestException
     */
    public function restoreNode(DriveNode $driveNode, ?string $workingCopyKey = null): DriveNode
    {
        $this->requireWriteRight($driveNode);
        if (!$driveNode->isTrashed()) {
            return $driveNode;
        }
        $parentNode = $this->find((int)$driveNode->parentNodeId, false);
        if (!$parentNode instanceof DriveNode || $parentNode->isTrashed()) {
            $driveSpace = $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
            $parentNode = $this->findRootNode($driveSpace);
            $driveNode->parentNodeId = (int)$parentNode?->id;
        }
        $restoredName = $this->findFreeName($parentNode, (string)$driveNode->name);
        $restoredCounts = new DriveNodeCounts();
        $this->markRestored($driveNode, (int)$driveNode->id, $restoredName, $restoredCounts);
        $this->adjustChildCount((int)$driveNode->parentNodeId, 1);
        $this->getDriveSpacesService()->adjustCounters((int)$driveNode->driveSpaceId, $restoredCounts->fileCount, $restoredCounts->folderCount);
        $this->getDriveChangesService()->recordChange($driveNode, DriveChangeEvent::EVENT_RESTORED, path: $this->buildPath($driveNode), workingCopyKey: $workingCopyKey);
        return $driveNode;
    }

    /** The name, or `name (2).ext`, `name (3).ext` … when it is taken in the folder */
    public function findFreeName(?DriveNode $folderNode, string $name): string
    {
        if ($folderNode === null || $this->findChildByName($folderNode, $name) === null) {
            return $name;
        }
        $extensionPosition = strrpos($name, '.');
        $baseName = $extensionPosition !== false && $extensionPosition > 0 ? substr($name, 0, $extensionPosition) : $name;
        $extension = $extensionPosition !== false && $extensionPosition > 0 ? substr($name, $extensionPosition) : '';
        for ($counter = 2; $counter < 1000; $counter++) {
            $candidateName = $baseName . ' (' . $counter . ')' . $extension;
            if ($this->findChildByName($folderNode, $candidateName) === null) {
                return $candidateName;
            }
        }
        return $baseName . ' (' . bin2hex(random_bytes(3)) . ')' . $extension;
    }

    /**
     * Live nodes of the given spaces whose folded name contains the folded query, files first.
     *
     * @param int[] $driveSpaceIds
     */
    public function searchNodesByName(array $driveSpaceIds, string $query, int $limit = 50, bool $includeSystem = false): DriveNodes
    {
        $foldedQuery = DriveNode::foldName($query);
        if ($driveSpaceIds === [] || $foldedQuery === '') {
            return new DriveNodes();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId IN (:driveSpaceIds)")
            ->andWhere("{$alias}.trashedAt IS NULL")
            ->andWhere("{$alias}.parentNodeId IS NOT NULL")
            ->andWhere("{$alias}.nameFolded LIKE :foldedQuery")
            ->setParameter('driveSpaceIds', array_values(array_map('intval', $driveSpaceIds)))
            ->setParameter('foldedQuery', '%' . addcslashes($foldedQuery, '%_\\') . '%')
            ->orderBy("{$alias}.nodeType", 'DESC')
            ->addOrderBy("{$alias}.nameSlot", 'ASC')
            ->setMaxResults(max(1, min(200, $limit)));
        if (!$includeSystem) {
            $queryBuilder->andWhere("{$alias}.isSystem = :isSystem")->setParameter('isSystem', false);
        }
        $driveNodes = $repoClass->find($queryBuilder, false);
        return $driveNodes instanceof DriveNodes ? $driveNodes : new DriveNodes();
    }

    /** Moves the live child counter of a folder */
    public function adjustChildCount(int $folderNodeId, int $delta): void
    {
        if ($folderNodeId <= 0 || $delta === 0) {
            return;
        }
        DBDriveNode::createQueryBuilder()
            ->update(DBDriveNode::BASE_ORM_MODEL, 'node')
            ->set('node.childCount', 'CASE WHEN node.childCount + :delta > 0 THEN node.childCount + :delta ELSE 0 END')
            ->where('node.id = :folderNodeId')
            ->setParameter('delta', $delta)
            ->setParameter('folderNodeId', $folderNodeId)
            ->getQuery()->execute();
    }

    /**
     * @return string[] The names of a path, without empty segments
     * @throws BadRequestException
     */
    public static function splitPath(string $path): array
    {
        $pathSegments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $pathSegment) {
            if ($pathSegment === '' || $pathSegment === '.') {
                continue;
            }
            if ($pathSegment === '..') {
                throw new BadRequestException(sprintf('"%s" leaves its folder (..).', $path));
            }
            $pathSegments[] = $pathSegment;
        }
        if (count($pathSegments) > self::MAX_PATH_DEPTH) {
            throw new BadRequestException(sprintf('"%s" is nested deeper than %d folders.', $path, self::MAX_PATH_DEPTH));
        }
        return $pathSegments;
    }

    /** True for a name configured as a system folder (`work`) at the root */
    public function isSystemFolderName(string $name): bool
    {
        $systemFolders = Config::get('CloudDrive.settings.systemFolders');
        return is_array($systemFolders) && isset($systemFolders[$name]);
    }

    /**
     * @throws BadRequestException
     */
    protected function requireWriteRight(DriveNode $driveNode): void
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_WRITE);
    }

    protected function isNodeOrAncestor(DriveNode $possibleAncestorNode, DriveNode $driveNode): bool
    {
        $currentNode = $driveNode;
        $depth = 0;
        while ($currentNode !== null && $depth < self::MAX_PATH_DEPTH) {
            if ($currentNode->id === $possibleAncestorNode->id) {
                return true;
            }
            if ($currentNode->isRoot()) {
                return false;
            }
            $parentNode = $this->find((int)$currentNode->parentNodeId, false);
            $currentNode = $parentNode instanceof DriveNode ? $parentNode : null;
            $depth++;
        }
        return false;
    }

    protected function markTrashed(DriveNode $driveNode, ?int $trashedWithNodeId, DriveNodeCounts $trashedCounts): void
    {
        if ($driveNode->isFolder()) {
            foreach ($this->findChildren($driveNode, null, self::MAX_LIST_LIMIT)->getElements() as $childNode) {
                $this->markTrashed($childNode, $trashedWithNodeId ?? (int)$driveNode->id, $trashedCounts);
            }
            $trashedCounts->folderCount++;
        } else {
            $trashedCounts->fileCount++;
        }
        $driveNode->trashedAt = DateTime::fromTimestamp(time());
        $driveNode->trashedByAccountId = $this->findActingAccountId();
        $driveNode->trashedWithNodeId = $trashedWithNodeId;
        $driveNode->nameSlot = DriveNode::buildTrashedNameSlot((int)$driveNode->id);
        $driveNode->updatePartialIgnoringRights('trashedAt', 'trashedByAccountId', 'trashedWithNodeId', 'nameSlot');
    }

    protected function markRestored(DriveNode $driveNode, int $restoredWithNodeId, string $restoredName, DriveNodeCounts $restoredCounts): void
    {
        $driveNode->trashedAt = null;
        $driveNode->trashedByAccountId = null;
        $driveNode->trashedWithNodeId = null;
        $driveNode->name = $restoredName;
        $driveNode->nameSlot = $restoredName;
        $driveNode->nameFolded = DriveNode::foldName($restoredName);
        $driveNode->updatePartialIgnoringRights('trashedAt', 'trashedByAccountId', 'trashedWithNodeId', 'name', 'nameSlot', 'nameFolded', 'parentNodeId');
        if ($driveNode->isFolder()) {
            $restoredCounts->folderCount++;
            $repoClass = $this->getEntitySetRepoClassInstance();
            $queryBuilder = $repoClass::createQueryBuilder(true);
            $alias = $repoClass::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.parentNodeId = :parentNodeId")
                ->andWhere("{$alias}.trashedWithNodeId = :trashedWithNodeId")
                ->setParameter('parentNodeId', (int)$driveNode->id)
                ->setParameter('trashedWithNodeId', $restoredWithNodeId);
            $childNodes = $repoClass->find($queryBuilder, false);
            foreach (($childNodes instanceof DriveNodes ? $childNodes->getElements() : []) as $childNode) {
                $this->markRestored($childNode, $restoredWithNodeId, (string)$childNode->name, $restoredCounts);
            }
        } else {
            $restoredCounts->fileCount++;
        }
    }

    protected function findActingAccountId(): ?int
    {
        $authAccount = AuthService::instance()->getAccount();
        return $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService;
    }
}
