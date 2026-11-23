<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `driveStatus`: the state of the own drive and the mounts. */
class DriveStatusRequestDto extends RequestDto
{
    /** @var bool|null Also list changes below the system folder `work/` */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $all = null;
}
