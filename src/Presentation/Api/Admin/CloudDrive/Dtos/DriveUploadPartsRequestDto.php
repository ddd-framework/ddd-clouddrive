<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** New presigned PUTs for parts of a running multipart upload (to resume). */
class DriveUploadPartsRequestDto extends RequestDto
{
    /** @var int The upload (blob) id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveBlobId;

    /** @var int[] The part numbers; omitted = all */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $partNumbers = [];
}
