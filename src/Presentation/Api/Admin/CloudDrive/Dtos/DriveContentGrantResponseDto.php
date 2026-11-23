<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A short-lived content URL. */
class DriveContentGrantResponseDto extends RestResponseDto
{
    /** @var DriveContentGrant The URL and its facts */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveContentGrant $grant;
}
