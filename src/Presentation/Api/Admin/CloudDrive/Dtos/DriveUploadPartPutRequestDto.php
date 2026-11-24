<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One part of a multipart upload: the request body as `Content-Type: application/octet-stream` with its
 * `Content-Length` (every part `partSizeBytes`, the last one the rest) and optionally `Content-MD5`. Sending a part
 * again replaces it.
 */
class DriveUploadPartPutRequestDto extends RequestDto
{
    /** @var int The upload (blob) id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveBlobId;

    /** @var int The part number, 1 to `partCount` */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $partNumber;
}
