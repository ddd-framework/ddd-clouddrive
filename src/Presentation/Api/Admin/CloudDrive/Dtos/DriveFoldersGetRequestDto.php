<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Every live folder of a space, flat (a folder tree in one call). */
class DriveFoldersGetRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var bool|null Also system folders (`work/`) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $includeSystem = null;
}
