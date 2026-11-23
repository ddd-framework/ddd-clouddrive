<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Content\DriveFileCards;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** File cards with short-lived content URLs. */
class DriveFileCardsResponseDto extends RestResponseDto
{
    /** @var DriveFileCards The cards the viewer may read */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveFileCards $cards;
}
