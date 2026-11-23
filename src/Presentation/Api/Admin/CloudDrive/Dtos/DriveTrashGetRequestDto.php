<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One page of a space's trash: what was trashed on its own, most recently trashed first. */
class DriveTrashGetRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var string|null The `nextCursor` of the previous page */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $cursor = null;

    /** @var int|null Entries per page (default 100, at most 1000) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;
}
