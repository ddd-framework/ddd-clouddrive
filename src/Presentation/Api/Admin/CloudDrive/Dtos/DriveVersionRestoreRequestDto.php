<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Makes an older version of a file its newest again. */
class DriveVersionRestoreRequestDto extends RequestDto
{
    /** @var int The file */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveNodeId;

    /** @var int The version to restore */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveFileVersionId;
}
