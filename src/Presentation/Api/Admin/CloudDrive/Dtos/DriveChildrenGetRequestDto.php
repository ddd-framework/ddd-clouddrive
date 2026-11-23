<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One page of a folder: folders first, then files, by name. */
class DriveChildrenGetRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var int|null The folder; omitted = the root */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $folderNodeId = null;

    /** @var string|null The `nextCursor` of the previous page */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $cursor = null;

    /** @var int|null Entries per page (default 100, at most 1000) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;

    /** @var bool|null Also system folders (`work/`) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $includeSystem = null;
}
