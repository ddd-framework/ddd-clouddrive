<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItems;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Files and folders, and the cursor of the next page. */
class DriveNodesGetResponseDto extends RestResponseDto
{
    /** @var DriveNodes The files and folders */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveNodes $driveNodes;

    /** @var DriveNodeListItems|null The same nodes as a file manager draws them (family, scan status, names, thumbnails) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveNodeListItems $items = null;

    /** @var string|null Pass it as `cursor` for the next page */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $nextCursor = null;

    /** @var int|null About how many entries the whole list has (from counters, for "about N"); null for a filtered list */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $approximateTotalCount = null;
}
