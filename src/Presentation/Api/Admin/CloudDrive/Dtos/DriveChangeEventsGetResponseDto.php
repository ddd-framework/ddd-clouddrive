<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEventListItems;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** History entries, newest first. */
class DriveChangeEventsGetResponseDto extends RestResponseDto
{
    /** @var DriveChangeEventListItems The entries */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveChangeEventListItems $items;
}
