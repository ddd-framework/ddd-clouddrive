<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Spaces;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One space as a space picker draws it: the name to show, the group it is listed under, its kind and audience, the
 * quota, and the caller's rights on it. Display name and group come from
 * {@see \DDD\Domain\CloudDrive\Services\DriveSpacesService::buildSpaceDisplayName()} and `buildSpaceGroupLabel()`,
 * which an application overrides with its own names.
 */
class DriveSpaceListItem extends ValueObject
{
    public int $id = 0;

    public ?string $title = null;

    public string $displayName = '';

    /** @var string|null The group a picker lists the space under; null = no group */
    public ?string $groupLabel = null;

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
