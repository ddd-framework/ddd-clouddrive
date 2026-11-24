<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The state of a running upload, to resume it: the parts the store holds already (`uploadedPartNumbers`). */
class DriveUploadPartsRequestDto extends RequestDto
{
    /** @var int The upload (blob) id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveBlobId;

    /** @var int[] Ignored (kept for older clients: parts are sent to the backend, nothing is presigned) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $partNumbers = [];
}
