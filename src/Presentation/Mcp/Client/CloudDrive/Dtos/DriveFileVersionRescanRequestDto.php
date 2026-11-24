<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `rescanDriveFileVersion`: the malware scan of one version whose scan failed, again. */
class DriveFileVersionRescanRequestDto extends RequestDto
{
    /** @var int The version id as getDriveFile lists it */
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $versionId = 0;
}
