<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersionListItems;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The version history of a file, newest first. */
class DriveFileVersionsGetResponseDto extends RestResponseDto
{
    /** @var DriveFileVersionListItems The versions */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveFileVersionListItems $items;
}
