<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\Date;

/**
 * What a file list asks for: one folder or the whole space (`isRecursive`), a name, a family, a creation window, the
 * caller's own uploads, an order and a page. Without filters and with the default order a folder lists like
 * {@see \DDD\Domain\CloudDrive\Services\DriveNodesService::findChildren()} (folders first, by name).
 */
class DriveNodeListQuery extends ValueObject
{
    public const string ORDER_BY_NAME = 'name';
    public const string ORDER_BY_CREATED = 'created';
    public const string ORDER_BY_SIZE = 'size';
    public const array ORDER_BY_VALUES = [self::ORDER_BY_NAME, self::ORDER_BY_CREATED, self::ORDER_BY_SIZE];

    public const string DIRECTION_ASC = 'asc';
    public const string DIRECTION_DESC = 'desc';

    /** @var int|null The folder; null = the root (ignored with `isRecursive`) */
    public ?int $folderNodeId = null;

    /** @var bool Every live node of the space, not only one folder's children */
    public bool $isRecursive = false;

    /** @var string|null Words of the name (case and accents do not matter) */
    public ?string $nameQuery = null;

    /** @var string|null One of {@see DriveFileFamily::FAMILIES}: files of that family only */
    public ?string $family = null;

    /** @var Date|null Created on or after this day */
    public ?Date $createdFrom = null;

    /** @var Date|null Created on or before this day */
    public ?Date $createdTo = null;

    /** @var int|null Only nodes this account created */
    public ?int $createdByAccountId = null;

    /** @var string One of ORDER_BY_VALUES */
    public string $orderBy = self::ORDER_BY_NAME;

    /** @var string `asc` or `desc` */
    public string $orderDirection = self::DIRECTION_ASC;

    /** @var int[] Only these nodes (at most {@see MAX_NODE_IDS}); empty = no restriction */
    public array $driveNodeIds = [];

    /** @var int The most node ids one list query takes */
    public const int MAX_NODE_IDS = 500;

    public bool $includeSystem = false;

    public int $limit = 100;

    /** @var int Rows skipped (the page cursor of a filtered or reordered list) */
    public int $offset = 0;

    /** True when nothing narrows the list (no name, family, window, creator or node ids): its size comes from counters */
    public function isUnfiltered(): bool
    {
        return $this->nameQuery === null && $this->family === null && $this->createdFrom === null && $this->createdTo === null
            && $this->createdByAccountId === null && $this->driveNodeIds === [];
    }

    /** True when the list is a plain folder listing (the name cursor of findChildren() applies) */
    public function isPlainFolderListing(): bool
    {
        return !$this->isRecursive && $this->nameQuery === null && $this->family === null && $this->createdFrom === null
            && $this->createdTo === null && $this->createdByAccountId === null && $this->driveNodeIds === []
            && $this->orderBy === self::ORDER_BY_NAME && $this->orderDirection === self::DIRECTION_ASC;
    }
}
