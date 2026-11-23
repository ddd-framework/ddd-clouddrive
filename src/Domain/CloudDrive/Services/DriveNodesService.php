<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveFileFamily;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveLocationReference;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveLocationResolution;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkResult;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeCounts;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItem;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItems;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListQuery;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Exceptions\DriveLocationNotFoundException;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNode;
use DDD\Domain\CloudDrive\Repo\DB\Nodes\DBDriveNodes;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Throwable;

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

    /** @var int Folders one folder-tree call answers at most */
    public const int MAX_FOLDER_TREE_SIZE = 5000;

    /** @var string The cursor of a filtered or reordered list: the offset of the next page */
    public const string OFFSET_CURSOR_PREFIX = 'o:';

    public const string BULK_ACTION_MOVE = 'MOVE';
    public const string BULK_ACTION_TRASH = 'TRASH';
    public const string BULK_ACTION_RESTORE = 'RESTORE';

    /** @var string[] Families a list filter accepts (OTHER is no filter: it is what matches none) */
    public const array FILTERABLE_FAMILIES = [
        DriveFileFamily::FAMILY_PDF, DriveFileFamily::FAMILY_SPREADSHEET, DriveFileFamily::FAMILY_DOCUMENT,
        DriveFileFamily::FAMILY_PRESENTATION, DriveFileFamily::FAMILY_TEXT, DriveFileFamily::FAMILY_IMAGE,
        DriveFileFamily::FAMILY_VECTOR, DriveFileFamily::FAMILY_VIDEO, DriveFileFamily::FAMILY_ARCHIVE,
    ];

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
            ->setMaxResults(max(1, min(self::MAX_LIST_LIMIT, $limit)));
        $this->applyFoldersFirstOrder($queryBuilder, $alias);
        $queryBuilder->addOrderBy("{$alias}.nameSlot", 'ASC');
        if (!$includeSystem) {
            $queryBuilder->andWhere("{$alias}.isSystem = :isSystem")->setParameter('isSystem', false);
        }
        $cursorParts = $cursor !== null && str_starts_with($cursor, self::LIST_CURSOR_PREFIX)
            ? explode("\t", substr($cursor, strlen(self::LIST_CURSOR_PREFIX)), 2) : null;
        if ($cursorParts !== null && count($cursorParts) === 2) {
            $folderRankExpression = $this->buildFolderRankExpression($alias);
            $queryBuilder->andWhere("({$folderRankExpression} > :cursorRank OR ({$folderRankExpression} = :cursorRank AND {$alias}.nameSlot > :cursorName))")
                ->setParameter('folderNodeType', DriveNode::NODE_TYPE_FOLDER)
                ->setParameter('cursorRank', $cursorParts[0] === DriveNode::NODE_TYPE_FOLDER ? 0 : 1)
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
     * The folders above a node, the root first and the node's own folder last (the root included).
     */
    public function findAncestors(DriveNode $driveNode): DriveNodes
    {
        $ancestorNodes = [];
        $currentNode = $driveNode;
        $depth = 0;
        while (!$currentNode->isRoot() && $depth < self::MAX_PATH_DEPTH) {
            $parentNode = $this->find((int)$currentNode->parentNodeId, false);
            if (!$parentNode instanceof DriveNode) {
                break;
            }
            array_unshift($ancestorNodes, $parentNode);
            $currentNode = $parentNode;
            $depth++;
        }
        $driveNodes = new DriveNodes();
        foreach ($ancestorNodes as $ancestorNode) {
            $driveNodes->add($ancestorNode);
        }
        return $driveNodes;
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

    /**
     * One page of a file list: a folder or the whole space, filtered by name, family, creation window and creator,
     * ordered by name, creation or size (folders first unless a family is asked for). A plain folder listing in the
     * default order is {@see findChildren()} with its name cursor; everything else pages by `offset`.
     *
     * @throws BadRequestException
     * @throws NotFoundException
     */
    public function findNodesByListQuery(DriveSpace $driveSpace, DriveNodeListQuery $driveNodeListQuery, ?string $cursor = null): DriveNodes
    {
        $limit = max(1, min(self::MAX_LIST_LIMIT, $driveNodeListQuery->limit));
        if ($driveNodeListQuery->isPlainFolderListing()) {
            $folderNode = $driveNodeListQuery->folderNodeId !== null
                ? $this->findNodeOfSpace($driveSpace, $driveNodeListQuery->folderNodeId)
                : $this->findRootNode($driveSpace);
            if ($folderNode === null || !$folderNode->isFolder()) {
                throw new NotFoundException('Folder not found.');
            }
            return $this->findChildren($folderNode, $cursor, $limit, $driveNodeListQuery->includeSystem);
        }
        if ($driveNodeListQuery->family !== null && !in_array($driveNodeListQuery->family, static::FILTERABLE_FAMILIES, true)) {
            throw new BadRequestException(sprintf('Unknown file family "%s"; one of %s.', $driveNodeListQuery->family, implode(', ', static::FILTERABLE_FAMILIES)));
        }
        if (!in_array($driveNodeListQuery->orderBy, DriveNodeListQuery::ORDER_BY_VALUES, true)) {
            throw new BadRequestException(sprintf('Unknown order "%s"; one of %s.', $driveNodeListQuery->orderBy, implode(', ', DriveNodeListQuery::ORDER_BY_VALUES)));
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.trashedAt IS NULL")
            ->setParameter('driveSpaceId', (int)$driveSpace->id);
        if ($driveNodeListQuery->isRecursive) {
            $queryBuilder->andWhere("{$alias}.parentNodeId IS NOT NULL");
        } else {
            $folderNode = $driveNodeListQuery->folderNodeId !== null
                ? $this->findNodeOfSpace($driveSpace, $driveNodeListQuery->folderNodeId)
                : $this->findRootNode($driveSpace);
            if ($folderNode === null || !$folderNode->isFolder()) {
                throw new NotFoundException('Folder not found.');
            }
            $queryBuilder->andWhere("{$alias}.parentNodeId = :parentNodeId")->setParameter('parentNodeId', (int)$folderNode->id);
        }
        if (!$driveNodeListQuery->includeSystem) {
            $queryBuilder->andWhere("{$alias}.isSystem = :isSystem")->setParameter('isSystem', false);
        }
        $foldedQuery = $driveNodeListQuery->nameQuery !== null ? DriveNode::foldName($driveNodeListQuery->nameQuery) : '';
        if ($foldedQuery !== '') {
            $queryBuilder->andWhere("{$alias}.nameFolded LIKE :foldedQuery")
                ->setParameter('foldedQuery', '%' . addcslashes($foldedQuery, '%_\\') . '%');
        }
        if ($driveNodeListQuery->family !== null) {
            $this->applyFamilyFilter($queryBuilder, $alias, $driveNodeListQuery->family);
        }
        if ($driveNodeListQuery->createdFrom !== null) {
            $queryBuilder->andWhere("{$alias}.created >= :createdFrom")->setParameter('createdFrom', $driveNodeListQuery->createdFrom->format('Y-m-d') . ' 00:00:00');
        }
        if ($driveNodeListQuery->createdTo !== null) {
            $queryBuilder->andWhere("{$alias}.created <= :createdTo")->setParameter('createdTo', $driveNodeListQuery->createdTo->format('Y-m-d') . ' 23:59:59');
        }
        if ($driveNodeListQuery->createdByAccountId !== null) {
            $queryBuilder->andWhere("{$alias}.createdByAccountId = :createdByAccountId")->setParameter('createdByAccountId', $driveNodeListQuery->createdByAccountId);
        }
        if ($driveNodeListQuery->driveNodeIds !== []) {
            if (count($driveNodeListQuery->driveNodeIds) > DriveNodeListQuery::MAX_NODE_IDS) {
                throw new BadRequestException(sprintf('At most %d node ids per list.', DriveNodeListQuery::MAX_NODE_IDS));
            }
            $queryBuilder->andWhere("{$alias}.id IN (:listedDriveNodeIds)")->setParameter('listedDriveNodeIds', array_values(array_map('intval', $driveNodeListQuery->driveNodeIds)));
        }
        $orderDirection = strtolower($driveNodeListQuery->orderDirection) === DriveNodeListQuery::DIRECTION_DESC ? 'DESC' : 'ASC';
        $orderColumn = match ($driveNodeListQuery->orderBy) {
            DriveNodeListQuery::ORDER_BY_CREATED => 'created',
            DriveNodeListQuery::ORDER_BY_SIZE => 'sizeBytes',
            default => 'nameSlot',
        };
        $this->applyFoldersFirstOrder($queryBuilder, $alias);
        $queryBuilder->addOrderBy("{$alias}.{$orderColumn}", $orderDirection)
            ->addOrderBy("{$alias}.id", $orderDirection)
            ->setFirstResult(static::parseOffsetCursor($cursor) ?? max(0, $driveNodeListQuery->offset))
            ->setMaxResults($limit);
        $driveNodes = $repoClass->find($queryBuilder, false);
        return $driveNodes instanceof DriveNodes ? $driveNodes : new DriveNodes();
    }

    /** Orders folders before files and links (a node type's name would sort FILE before FOLDER) */
    protected function applyFoldersFirstOrder(DoctrineQueryBuilder $queryBuilder, string $alias): void
    {
        $queryBuilder->addSelect($this->buildFolderRankExpression($alias) . ' AS HIDDEN folderRank')
            ->setParameter('folderNodeType', DriveNode::NODE_TYPE_FOLDER)
            ->addOrderBy('folderRank', 'ASC');
    }

    /** 0 for a folder, 1 for a file or a link */
    protected function buildFolderRankExpression(string $alias): string
    {
        return "(CASE WHEN {$alias}.nodeType = :folderNodeType THEN 0 ELSE 1 END)";
    }

    /** The cursor of an offset-paged list (a filtered or reordered one): `o:<offset>` */
    public static function buildOffsetCursor(int $offset): string
    {
        return self::OFFSET_CURSOR_PREFIX . $offset;
    }

    /** The offset of an `o:<offset>` cursor; null for any other cursor */
    public static function parseOffsetCursor(?string $cursor): ?int
    {
        if ($cursor === null || !str_starts_with($cursor, self::OFFSET_CURSOR_PREFIX)) {
            return null;
        }
        $offset = substr($cursor, strlen(self::OFFSET_CURSOR_PREFIX));
        return ctype_digit($offset) ? (int)$offset : null;
    }

    /**
     * Files of one family: its content types (the exact ones of every family win over a prefix), and files whose type
     * tells nothing by their extension ({@see DriveFileFamily}).
     */
    protected function applyFamilyFilter(DoctrineQueryBuilder $queryBuilder, string $alias, string $family): void
    {
        $familyConditions = [];
        $exactContentTypes = DriveFileFamily::CONTENT_TYPES_BY_FAMILY[$family] ?? [];
        if ($exactContentTypes !== []) {
            $familyConditions[] = "{$alias}.contentType IN (:familyContentTypes)";
            $queryBuilder->setParameter('familyContentTypes', $exactContentTypes);
        }
        $prefixConditions = [];
        foreach (array_values(DriveFileFamily::CONTENT_TYPE_PREFIXES_BY_FAMILY[$family] ?? []) as $prefixIndex => $contentTypePrefix) {
            $prefixConditions[] = "{$alias}.contentType LIKE :familyContentTypePrefix{$prefixIndex}";
            $queryBuilder->setParameter('familyContentTypePrefix' . $prefixIndex, addcslashes($contentTypePrefix, '%_\\') . '%');
        }
        if ($prefixConditions !== []) {
            $otherFamiliesContentTypes = DriveFileFamily::findContentTypesOfOtherFamilies($family);
            $familyConditions[] = '((' . implode(' OR ', $prefixConditions) . ')' . ($otherFamiliesContentTypes !== [] ? " AND {$alias}.contentType NOT IN (:otherFamiliesContentTypes)" : '') . ')';
            if ($otherFamiliesContentTypes !== []) {
                $queryBuilder->setParameter('otherFamiliesContentTypes', $otherFamiliesContentTypes);
            }
        }
        $extensionConditions = [];
        foreach (array_values(DriveFileFamily::EXTENSIONS_BY_FAMILY[$family] ?? []) as $extensionIndex => $extension) {
            $extensionConditions[] = "{$alias}.nameFolded LIKE :familyExtension{$extensionIndex}";
            $queryBuilder->setParameter('familyExtension' . $extensionIndex, '%.' . $extension);
        }
        if ($extensionConditions !== []) {
            $familyConditions[] = "(({$alias}.contentType IS NULL OR {$alias}.contentType IN (:unspecificContentTypes)) AND (" . implode(' OR ', $extensionConditions) . '))';
            $queryBuilder->setParameter('unspecificContentTypes', DriveFileFamily::UNSPECIFIC_CONTENT_TYPES);
        }
        $queryBuilder->andWhere("{$alias}.nodeType = :familyNodeType")->setParameter('familyNodeType', DriveNode::NODE_TYPE_FILE);
        $queryBuilder->andWhere($familyConditions !== [] ? '(' . implode(' OR ', $familyConditions) . ')' : "{$alias}.id IS NULL");
    }

    /**
     * About how many entries an unfiltered list has, from counters: a folder's live children, or every live file and
     * folder of the space for a recursive list. Null for a filtered list (a count would cost a query per page).
     */
    public function findApproximateTotalCount(DriveSpace $driveSpace, DriveNodeListQuery $driveNodeListQuery): ?int
    {
        if (!$driveNodeListQuery->isUnfiltered()) {
            return null;
        }
        if ($driveNodeListQuery->isRecursive) {
            return (int)$driveSpace->fileCount + max(0, (int)$driveSpace->folderCount);
        }
        $folderNode = $driveNodeListQuery->folderNodeId !== null
            ? $this->findNodeOfSpace($driveSpace, $driveNodeListQuery->folderNodeId)
            : $this->findRootNode($driveSpace);
        return $folderNode !== null && $folderNode->isFolder() ? (int)$folderNode->childCount : null;
    }

    /**
     * One page of a space's trash: the nodes trashed on their own (what a folder took along is restored and purged
     * with it), most recently trashed first.
     */
    public function findTrashedNodes(DriveSpace $driveSpace, int $offset = 0, int $limit = 100): DriveNodes
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.trashedAt IS NOT NULL")
            ->andWhere("{$alias}.trashedWithNodeId IS NULL")
            ->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->orderBy("{$alias}.trashedAt", 'DESC')
            ->addOrderBy("{$alias}.id", 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(self::MAX_LIST_LIMIT, $limit)));
        $driveNodes = $repoClass->find($queryBuilder, false);
        return $driveNodes instanceof DriveNodes ? $driveNodes : new DriveNodes();
    }

    /**
     * Every live folder of a space (the root included), flat, by name: a folder tree in one call. At most `$limit`
     * (one more is read to tell a cut list).
     */
    public function findFoldersOfSpace(DriveSpace $driveSpace, int $limit = self::MAX_FOLDER_TREE_SIZE, bool $includeSystem = false): DriveNodes
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.driveSpaceId = :driveSpaceId")
            ->andWhere("{$alias}.nodeType = :nodeType")
            ->andWhere("{$alias}.trashedAt IS NULL")
            ->setParameter('driveSpaceId', (int)$driveSpace->id)
            ->setParameter('nodeType', DriveNode::NODE_TYPE_FOLDER)
            ->orderBy("{$alias}.nameSlot", 'ASC')
            ->setMaxResults(max(1, min(self::MAX_FOLDER_TREE_SIZE, $limit)) + 1);
        if (!$includeSystem) {
            $queryBuilder->andWhere("{$alias}.isSystem = :isSystem")->setParameter('isSystem', false);
        }
        $driveNodes = $repoClass->find($queryBuilder, false);
        return $driveNodes instanceof DriveNodes ? $driveNodes : new DriveNodes();
    }

    /**
     * The list entries of nodes for a file manager: family, scan status of what each file shows, a pending newer
     * version, uncommitted working-copy changes, people's display names, thumbnails of released raster images and,
     * for trashed nodes, the purge date. A handful of batch queries for the whole page.
     */
    public function buildListItems(DriveNodes $driveNodes, DriveSpace $driveSpace): DriveNodeListItems
    {
        $versionIds = [];
        $accountIds = [];
        $nodeIds = [];
        foreach ($driveNodes->getElements() as $driveNode) {
            $nodeIds[] = (int)$driveNode->id;
            foreach ([$driveNode->currentVersionId, $driveNode->latestVersionId] as $versionId) {
                if ($versionId !== null) {
                    $versionIds[] = (int)$versionId;
                }
            }
            foreach ([$driveNode->createdByAccountId, $driveNode->trashedByAccountId] as $accountId) {
                if ($accountId !== null) {
                    $accountIds[] = (int)$accountId;
                }
            }
        }
        $driveFileVersionsById = [];
        foreach ($this->getDriveVersionsService()->findVersionsByIds($versionIds)->getElements() as $driveFileVersion) {
            $driveFileVersionsById[(int)$driveFileVersion->id] = $driveFileVersion;
        }
        $blobIds = [];
        foreach ($driveFileVersionsById as $driveFileVersion) {
            $blobIds[] = (int)$driveFileVersion->driveBlobId;
        }
        $driveBlobsById = [];
        foreach ($this->getDriveBlobsService()->findBlobsByIds($blobIds)->getElements() as $driveBlob) {
            $driveBlobsById[(int)$driveBlob->id] = $driveBlob;
        }
        $nodeIdsWithStagedChanges = array_flip($this->getDriveWorkingCopiesService()->findNodeIdsWithStagedChanges((int)$driveSpace->id, $nodeIds));
        $creationOnBehalfOfAccountIds = $this->getDriveChangesService()->findCreationOnBehalfOfAccountIds($nodeIds);
        foreach ($creationOnBehalfOfAccountIds as $onBehalfOfAccountId) {
            $accountIds[] = $onBehalfOfAccountId;
        }
        $audience = $this->getDriveSpacesService()->getCurrentAudience();
        /** @var DriveActorNamesService $driveActorNamesService */
        $driveActorNamesService = DDDService::instance()->getService(DriveActorNamesService::class);
        $accountDisplayNames = $driveActorNamesService->findActorDisplayNames($accountIds, $audience);
        $thumbnailLifetimeSeconds = max(30, (int)Config::get('CloudDrive.settings.downloadUrlLifetimeSeconds'));
        /** @var DriveContentGrantsService $driveContentGrantsService */
        $driveContentGrantsService = DDDService::instance()->getService(DriveContentGrantsService::class);
        $driveNodeListItems = new DriveNodeListItems();
        foreach ($driveNodes->getElements() as $driveNode) {
            $driveNodeListItem = new DriveNodeListItem();
            $driveNodeListItem->id = (int)$driveNode->id;
            $driveNodeListItem->driveSpaceId = (int)$driveNode->driveSpaceId;
            $driveNodeListItem->parentNodeId = $driveNode->parentNodeId;
            $driveNodeListItem->nodeType = (string)$driveNode->nodeType;
            $driveNodeListItem->name = (string)$driveNode->name;
            $driveNodeListItem->description = $driveNode->description;
            $driveNodeListItem->tags = $driveNode->getTagList();
            $driveNodeListItem->isContentUntrusted = (bool)$driveNode->isContentUntrusted;
            $driveNodeListItem->isSystem = (bool)$driveNode->isSystem;
            $driveNodeListItem->hasWorkingCopyChanges = isset($nodeIdsWithStagedChanges[(int)$driveNode->id]);
            $driveNodeListItem->createdByAccountId = $driveNode->createdByAccountId;
            $driveNodeListItem->createdOnBehalfOfAccountId = $creationOnBehalfOfAccountIds[(int)$driveNode->id] ?? null;
            $driveNodeListItem->createdByName = $driveNode->createdByAccountId !== null
                ? $driveActorNamesService->buildActorDisplayName((int)$driveNode->createdByAccountId, $driveNodeListItem->createdOnBehalfOfAccountId, $audience, $accountDisplayNames)
                : null;
            $driveNodeListItem->createdOnBehalfOfName = $driveNodeListItem->createdOnBehalfOfAccountId !== null
                ? ($accountDisplayNames[$driveNodeListItem->createdOnBehalfOfAccountId] ?? null) : null;
            $driveNodeListItem->createdAt = $driveNode->getCreatedTime();
            $driveNodeListItem->updatedAt = $driveNode->getLastEditTime();
            $driveNodeListItem->trashedAt = $driveNode->trashedAt;
            $driveNodeListItem->trashedByAccountId = $driveNode->trashedByAccountId;
            $driveNodeListItem->trashedByName = $driveNode->trashedByAccountId !== null ? ($accountDisplayNames[(int)$driveNode->trashedByAccountId] ?? null) : null;
            $driveNodeListItem->purgeAfter = $driveNode->trashedAt !== null
                ? DateTime::fromTimestamp($driveNode->trashedAt->getTimestamp() + $driveSpace->getTrashRetentionDays() * 86400) : null;
            if ($driveNode->isFolder()) {
                $driveNodeListItem->childCount = (int)$driveNode->childCount;
                $driveNodeListItems->add($driveNodeListItem);
                continue;
            }
            $driveNodeListItem->extension = DriveFileFamily::findExtension((string)$driveNode->name);
            $driveNodeListItem->family = DriveFileFamily::findFamily($driveNode->contentType, (string)$driveNode->name);
            $driveNodeListItem->contentType = $driveNode->contentType;
            $driveNodeListItem->sizeBytes = $driveNode->sizeBytes;
            $driveNodeListItem->versionNumber = $driveNode->versionNumber;
            $shownVersion = $driveFileVersionsById[(int)($driveNode->currentVersionId ?? $driveNode->latestVersionId)] ?? null;
            $shownBlob = $shownVersion !== null ? ($driveBlobsById[(int)$shownVersion->driveBlobId] ?? null) : null;
            $driveNodeListItem->scanStatus = $shownBlob?->findScanStatus();
            $latestVersion = $driveFileVersionsById[(int)$driveNode->latestVersionId] ?? null;
            $latestBlob = $latestVersion !== null ? ($driveBlobsById[(int)$latestVersion->driveBlobId] ?? null) : null;
            $driveNodeListItem->hasPendingVersion = $driveNode->currentVersionId !== null
                && $driveNode->latestVersionId !== null
                && (int)$driveNode->latestVersionId !== (int)$driveNode->currentVersionId
                && $latestBlob !== null
                && in_array($latestBlob->findScanStatus(), [DriveBlob::SCAN_STATUS_UPLOADING, DriveBlob::SCAN_STATUS_PENDING], true);
            if ($driveNode->currentVersionId !== null && $latestVersion !== null && $latestBlob !== null
                && (int)$driveNode->latestVersionId !== (int)$driveNode->currentVersionId
            ) {
                $driveNodeListItem->latestVersionNumber = $latestVersion->versionNumber;
                $driveNodeListItem->latestVersionScanStatus = $latestBlob->findScanStatus();
                $driveNodeListItem->latestVersionStateReason = $latestBlob->isReadableByPeople() ? null : $latestBlob->stateReason;
            }
            if ($shownBlob !== null && $shownBlob->isReadableByPeople() && UntrustedContentHeaders::isInlineContentType($shownBlob->contentType)
                && $driveContentGrantsService->isVariantAllowed($driveNode, DriveContentGrant::PURPOSE_THUMBNAIL, $this->getDriveSpacesService()->getCurrentAudience())
            ) {
                $driveNodeListItem->thumbnailUrl = $this->getDriveBlobsService()->createDownloadRequestUrl($shownBlob, (string)$driveNode->name, false, $thumbnailLifetimeSeconds);
            }
            $driveNodeListItems->add($driveNodeListItem);
        }
        return $driveNodeListItems;
    }

    /**
     * Display names of accounts (creator, uploader, who trashed, actors of the history), keyed by account id. The
     * module knows no names: an application overrides this (same-path subclass of the service) with its own; an
     * account without an entry shows its id only.
     *
     * @param int[] $accountIds
     * @return string[] Display names keyed by account id
     */
    public function findAccountDisplayNames(array $accountIds): array
    {
        return [];
    }

    /**
     * Moves up to {@see DriveNodeBulkResult::MAX_NODES} nodes of one space into a folder of it, in one transaction.
     * A node that cannot move (a name taken there, a folder into itself, the root) is skipped with its reason.
     *
     * @param int[] $driveNodeIds
     * @throws BadRequestException
     */
    public function moveNodes(DriveSpace $driveSpace, array $driveNodeIds, DriveNode $targetFolderNode): DriveNodeBulkResult
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
        if (!$targetFolderNode->isFolder() || $targetFolderNode->isTrashed() || (int)$targetFolderNode->driveSpaceId !== (int)$driveSpace->id) {
            throw new BadRequestException('The target is no live folder of this space.');
        }
        return $this->applyBulkAction($driveSpace, $driveNodeIds, self::BULK_ACTION_MOVE, $targetFolderNode);
    }

    /**
     * Trashes up to {@see DriveNodeBulkResult::MAX_NODES} nodes of one space in one transaction (folders with their
     * subtree).
     *
     * @param int[] $driveNodeIds
     * @throws BadRequestException
     */
    public function trashNodes(DriveSpace $driveSpace, array $driveNodeIds): DriveNodeBulkResult
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
        return $this->applyBulkAction($driveSpace, $driveNodeIds, self::BULK_ACTION_TRASH);
    }

    /**
     * Restores up to {@see DriveNodeBulkResult::MAX_NODES} trashed nodes of one space in one transaction.
     *
     * @param int[] $driveNodeIds
     * @throws BadRequestException
     */
    public function restoreNodes(DriveSpace $driveSpace, array $driveNodeIds): DriveNodeBulkResult
    {
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
        return $this->applyBulkAction($driveSpace, $driveNodeIds, self::BULK_ACTION_RESTORE);
    }

    /**
     * The nodes of a bulk request: distinct, at most {@see DriveNodeBulkResult::MAX_NODES}, all of the space.
     *
     * @param int[] $driveNodeIds
     * @return int[]
     * @throws BadRequestException
     */
    public static function normalizeBulkNodeIds(array $driveNodeIds): array
    {
        $normalizedNodeIds = [];
        foreach ($driveNodeIds as $driveNodeId) {
            if ((int)$driveNodeId > 0) {
                $normalizedNodeIds[(int)$driveNodeId] = (int)$driveNodeId;
            }
        }
        $normalizedNodeIds = array_values($normalizedNodeIds);
        if ($normalizedNodeIds === []) {
            throw new BadRequestException('No nodes given.');
        }
        if (count($normalizedNodeIds) > DriveNodeBulkResult::MAX_NODES) {
            throw new BadRequestException(sprintf('At most %d nodes per request (%d given).', DriveNodeBulkResult::MAX_NODES, count($normalizedNodeIds)));
        }
        return $normalizedNodeIds;
    }

    /**
     * Applies one action to the nodes in one transaction: a node of another space, or one the action refuses, is
     * skipped with its reason; any other failure rolls everything back.
     *
     * @param int[] $driveNodeIds
     * @throws BadRequestException
     */
    protected function applyBulkAction(DriveSpace $driveSpace, array $driveNodeIds, string $bulkAction, ?DriveNode $targetFolderNode = null): DriveNodeBulkResult
    {
        $driveNodeIds = static::normalizeBulkNodeIds($driveNodeIds);
        $driveNodeBulkResult = new DriveNodeBulkResult();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($driveNodeIds as $driveNodeId) {
                $driveNode = $this->findNodeOfSpace($driveSpace, $driveNodeId);
                if ($driveNode === null) {
                    $driveNodeBulkResult->addSkip($driveNodeId, 'not found in this space');
                    continue;
                }
                try {
                    $this->applyBulkActionToNode($driveNode, $bulkAction, $targetFolderNode, $driveNodeBulkResult);
                } catch (BadRequestException $refusal) {
                    $driveNodeBulkResult->addSkip($driveNodeId, $refusal->getMessage());
                }
            }
            $connection->commit();
        } catch (Throwable $throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
        return $driveNodeBulkResult;
    }

    /**
     * @throws BadRequestException
     */
    protected function applyBulkActionToNode(DriveNode $driveNode, string $bulkAction, ?DriveNode $targetFolderNode, DriveNodeBulkResult $driveNodeBulkResult): void
    {
        $isFolder = $driveNode->isFolder();
        if ($bulkAction === self::BULK_ACTION_MOVE) {
            if ($driveNode->isTrashed()) {
                throw new BadRequestException('trashed');
            }
            $this->moveNode($driveNode, $targetFolderNode, null);
        } elseif ($bulkAction === self::BULK_ACTION_TRASH) {
            if ($driveNode->isTrashed()) {
                throw new BadRequestException('already in the trash');
            }
            $this->trashNode($driveNode);
        } else {
            if (!$driveNode->isTrashed()) {
                throw new BadRequestException('not in the trash');
            }
            if ($driveNode->trashedWithNodeId !== null) {
                throw new BadRequestException('trashed with its folder: restore the folder');
            }
            $this->restoreNode($driveNode);
        }
        $driveNodeBulkResult->processedCount++;
        $isFolder ? $driveNodeBulkResult->folderCount++ : $driveNodeBulkResult->fileCount++;
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
     * The file or folder a pasted location names, under the caller's rights ({@see DriveLocationReference}): a path in
     * `$requestedDriveSpace`, `drive/<nodeId>` or a mount path of another space (the answer then carries that space).
     * A trashed node is resolved by reference for the INTERNAL audience only; a path never reaches the trash.
     *
     * @throws DriveLocationNotFoundException the walk stopped: the missing segment and the deepest existing folder
     * @throws BadRequestException a path that leaves its folder (`..`) or is nested too deep
     */
    public function resolveLocation(DriveSpace $requestedDriveSpace, string $location): DriveLocationResolution
    {
        $driveLocationReference = DriveLocationReference::fromLocation($location);
        $driveSpacesService = $this->getDriveSpacesService();
        if ($driveLocationReference->isNodeReference()) {
            return $this->resolveNodeReference($driveLocationReference);
        }
        $driveSpace = $requestedDriveSpace;
        $path = $driveLocationReference->path;
        if ($driveLocationReference->mountName !== null) {
            $mountedDriveSpace = $driveSpacesService->findReadableSpaceByMountName($driveLocationReference->mountName);
            if ($mountedDriveSpace !== null) {
                $driveSpace = $mountedDriveSpace;
            } elseif ($driveLocationReference->pathWhenMountIsUnknown !== null) {
                $path = $driveLocationReference->pathWhenMountIsUnknown;
            } else {
                throw DriveLocationNotFoundException::forMissingSegment(null, $path, $driveLocationReference->mountName, null);
            }
        }
        $currentNode = $this->findRootNode($driveSpace);
        if ($currentNode === null) {
            throw DriveLocationNotFoundException::forMissingSegment((int)$driveSpace->id, $path, '/', null);
        }
        foreach (static::splitPath($path) as $pathSegment) {
            $childNode = $currentNode->isFolder() ? $this->findChildByName($currentNode, $pathSegment) : null;
            if ($childNode === null) {
                $deepestFolderNode = $currentNode->isFolder() ? $currentNode : null;
                throw DriveLocationNotFoundException::forMissingSegment(
                    (int)$driveSpace->id,
                    $path,
                    $pathSegment,
                    $deepestFolderNode !== null ? $this->buildListItemOfNode($deepestFolderNode, $driveSpace) : null
                );
            }
            $currentNode = $childNode;
        }
        $driveLocationResolution = new DriveLocationResolution();
        $driveLocationResolution->driveSpace = $driveSpace;
        $driveLocationResolution->driveNode = $currentNode;
        $driveLocationResolution->path = $this->buildPath($currentNode);
        return $driveLocationResolution;
    }

    /**
     * The node of a reference `drive/<nodeId>` in any space the caller may read.
     *
     * @throws DriveLocationNotFoundException
     */
    protected function resolveNodeReference(DriveLocationReference $driveLocationReference): DriveLocationResolution
    {
        $reference = 'drive/' . $driveLocationReference->driveNodeId;
        $driveNode = $this->find((int)$driveLocationReference->driveNodeId, false);
        $driveSpace = $driveNode instanceof DriveNode
            ? $this->getDriveSpacesService()->findSpaceWithRight((int)$driveNode->driveSpaceId, DBDriveSpace::RIGHT_READ)
            : null;
        $isHiddenFromAudience = $driveNode instanceof DriveNode && $driveNode->isTrashed()
            && $this->getDriveSpacesService()->getCurrentAudience() !== DriveSpace::AUDIENCE_INTERNAL;
        if (!$driveNode instanceof DriveNode || $driveSpace === null || $isHiddenFromAudience) {
            throw DriveLocationNotFoundException::forMissingSegment(null, null, $reference, null);
        }
        $driveLocationResolution = new DriveLocationResolution();
        $driveLocationResolution->driveSpace = $driveSpace;
        $driveLocationResolution->driveNode = $driveNode;
        $driveLocationResolution->path = $this->buildPath($driveNode);
        return $driveLocationResolution;
    }

    /** One node as a file manager draws it */
    public function buildListItemOfNode(DriveNode $driveNode, DriveSpace $driveSpace): ?DriveNodeListItem
    {
        $driveNodes = new DriveNodes();
        $driveNodes->add($driveNode);
        return $this->buildListItems($driveNodes, $driveSpace)->first();
    }

    /**
     * The host's deep link into its drive screen for a file or folder (`CloudDrive.settings.adminLinkTemplate`); null
     * when no template is set. A file links to its folder with the file opened.
     *
     * @param string|null $path the node's path when the caller has it already
     */
    public function buildAdminLink(DriveNode $driveNode, ?string $path = null): ?string
    {
        $adminLinkTemplate = $this->getAdminLinkTemplate();
        if ($adminLinkTemplate === null) {
            return null;
        }
        $folderNodeId = $driveNode->isFolder() ? (int)$driveNode->id : ($driveNode->parentNodeId !== null ? (int)$driveNode->parentNodeId : null);
        return static::fillAdminLinkTemplate(
            $adminLinkTemplate,
            (int)$driveNode->driveSpaceId,
            (int)$driveNode->id,
            $folderNodeId,
            $path ?? $this->buildPath($driveNode)
        );
    }

    /** `CloudDrive.settings.adminLinkTemplate`, null when unset or empty */
    public function getAdminLinkTemplate(): ?string
    {
        $adminLinkTemplate = Config::get('CloudDrive.settings.adminLinkTemplate');
        return is_string($adminLinkTemplate) && trim($adminLinkTemplate) !== '' ? trim($adminLinkTemplate) : null;
    }

    /**
     * Fills a link template: `{spaceId}`, `{nodeId}`, `{folderNodeId}` (empty when unknown) and `{path}` (the path with
     * a leading slash, URL-encoded as one value).
     */
    public static function fillAdminLinkTemplate(string $adminLinkTemplate, int $driveSpaceId, ?int $driveNodeId, ?int $folderNodeId, string $path): string
    {
        return strtr($adminLinkTemplate, [
            '{spaceId}' => (string)$driveSpaceId,
            '{nodeId}' => $driveNodeId !== null ? (string)$driveNodeId : '',
            '{folderNodeId}' => $folderNodeId !== null ? (string)$folderNodeId : '',
            '{path}' => rawurlencode('/' . ltrim($path, '/')),
        ]);
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

    /** The acting account ({@see DriveChangesService::findActingAccountIdOfCurrentCaller()}, the one hook a host overrides) */
    protected function findActingAccountId(): ?int
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService->findActingAccountIdOfCurrentCaller();
    }

    /** The account the acting account acts for ({@see DriveChangesService::findOnBehalfOfAccountIdOfCurrentCaller()}) */
    protected function findOnBehalfOfAccountId(): ?int
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService->findOnBehalfOfAccountIdOfCurrentCaller();
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

    protected function getDriveVersionsService(): DriveVersionsService
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        return $driveVersionsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        return $driveBlobsService;
    }

    protected function getDriveWorkingCopiesService(): DriveWorkingCopiesService
    {
        /** @var DriveWorkingCopiesService $driveWorkingCopiesService */
        $driveWorkingCopiesService = DDDService::instance()->getService(DriveWorkingCopiesService::class);
        return $driveWorkingCopiesService;
    }
}
