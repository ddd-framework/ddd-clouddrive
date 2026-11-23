<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `restoreDriveFiles`: trashed files or folders back to their folder. */
class DriveFilesRestoreRequestDto extends RequestDto
{
    /** @var string[] The references `drive/<id>` of the trashed files */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $references = [];
}
