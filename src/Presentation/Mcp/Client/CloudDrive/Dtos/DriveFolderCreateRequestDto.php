<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `createDriveFolder`: a folder in a mounted space (in the own drive use mkdir and commit). */
class DriveFolderCreateRequestDto extends RequestDto
{
    /** @var string The parent folder: workspace path or `drive/<id>` of a folder in a mounted space */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $parentPath = '';

    /** @var string The new folder's name (no slash) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $name = '';
}
