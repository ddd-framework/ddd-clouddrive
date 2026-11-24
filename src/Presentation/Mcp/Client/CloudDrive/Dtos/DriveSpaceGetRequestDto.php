<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `getDriveSpace`: the facts of one mounted space (quota, use, write lock, top-level folders). */
class DriveSpaceGetRequestDto extends RequestDto
{
    /** @var string `own` or a mount name as driveStatus lists it (e.g. `library`, `space-12`) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $space = '';
}
