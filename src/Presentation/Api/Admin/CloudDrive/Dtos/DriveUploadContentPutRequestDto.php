<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The whole content of a single-part upload: the request body as `Content-Type: application/octet-stream` with its
 * `Content-Length` (streamed into the store, never held in memory).
 */
class DriveUploadContentPutRequestDto extends RequestDto
{
    /** @var int The upload (blob) id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveBlobId;
}
