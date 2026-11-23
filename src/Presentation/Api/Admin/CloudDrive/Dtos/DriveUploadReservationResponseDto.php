<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadReservation;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** How to send the bytes of a reserved upload. */
class DriveUploadReservationResponseDto extends RestResponseDto
{
    /** @var DriveUploadReservation The upload */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveUploadReservation $reservation;
}
