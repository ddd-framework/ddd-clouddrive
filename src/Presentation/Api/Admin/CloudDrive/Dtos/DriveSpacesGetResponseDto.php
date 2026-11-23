<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceListItems;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The drive spaces the caller may read. */
class DriveSpacesGetResponseDto extends RestResponseDto
{
    /** @var DriveSpaces The spaces */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveSpaces $driveSpaces;

    /** @var DriveSpaceListItems|null The same spaces for a picker: display name, group, quota, the caller's rights */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveSpaceListItems $items = null;
}
