<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The bytes of an upload (or of one part) arrived in the store. */
class DriveUploadBytesResponseDto extends RestResponseDto
{
    /** @var int The upload (blob) id */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $driveBlobId = 0;

    /** @var int|null The part number; null for the whole content */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $partNumber = null;

    /** @var int The bytes stored */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $sizeBytes = 0;
}
