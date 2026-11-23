<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItem;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItems;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Where a pasted location points: its space (another one than requested for a mount path or a reference) and the node. */
class DriveLocationResolveResponseDto extends RestResponseDto
{
    /** @var int The space of the node (switch to it when it differs from the requested one) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $driveSpaceId;

    /** @var DriveNodeListItem|null The file or folder */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveNodeListItem $item = null;

    /** @var DriveNodeListItems|null The folders above it, the root first and its own folder last */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveNodeListItems $ancestors = null;

    /** @var string The node's path in its space (no leading slash; empty = the root) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $path;

    /** @var string|null The host's deep link to it (`CloudDrive.settings.adminLinkTemplate`), null without a template */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $adminLink = null;
}
