<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The drive spaces the caller may read. */
class DriveSpacesGetResponseDto extends RestResponseDto
{
    /** @var DriveSpaces The spaces */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveSpaces $driveSpaces;
}
