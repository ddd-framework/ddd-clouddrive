<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceListItem;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One drive space. */
class DriveSpaceGetResponseDto extends RestResponseDto
{
    /** @var DriveSpace The space */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveSpace $driveSpace;

    /** @var DriveSpaceListItem|null Display name, group, quota and the caller's rights */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveSpaceListItem $item = null;
}
