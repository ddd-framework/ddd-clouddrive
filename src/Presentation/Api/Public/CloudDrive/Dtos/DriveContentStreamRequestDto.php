<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Public\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A content URL of the drive: the signed token of a grant (`Range`, `If-None-Match` and `If-Range` are honoured). */
class DriveContentStreamRequestDto extends RequestDto
{
    /** @var string The signed content token */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $contentToken;
}
