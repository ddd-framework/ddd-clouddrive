<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One file or folder by id. */
class DriveNodeGetRequestDto extends RequestDto
{
    /** @var int The file or folder */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveNodeId;
}
