<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One space as a space picker draws it: the name to show, the group it is listed under, its kind and audience, the
 * quota, and the caller's rights on it. Display name, group label and group code come from
 * {@see \DDD\Domain\CloudDrive\Services\DriveSpacesService::buildSpaceDisplayName()}, `buildSpaceGroupLabel()` and
 * `buildSpaceDisplayGroup()`; the list order from `compareSpaceListItems()`. An application overrides them with its own.
 */
class DriveSpaceListItem extends ValueObject
{
    public int $id = 0;

    public ?string $title = null;

    public string $displayName = '';

    /** @var string|null The group a picker lists the space under; null = no group */
    public ?string $groupLabel = null;

    /** @var string|null A code of the group for ordering and icons (the host's own codes; default the kind in upper case) */
    public ?string $displayGroup = null;

    public ?string $spaceKind = null;

    /** @var string INTERNAL or SHARED */
    public ?string $audience = null;

    public ?int $rootNodeId = null;

    public DriveSpaceQuota $quota;

    public bool $canWrite = false;

    public bool $canManage = false;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->id);
    }
}
