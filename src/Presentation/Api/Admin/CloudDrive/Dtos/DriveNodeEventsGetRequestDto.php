<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The newest history entries of a file or folder. */
class DriveNodeEventsGetRequestDto extends RequestDto
{
    /** @var int The file or folder */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveNodeId;

    /** @var int|null Entries (default 20, at most 200) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;
}
