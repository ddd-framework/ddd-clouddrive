<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `getDriveFile`: the facts and the versions of one file, without a download. */
class DriveFileGetRequestDto extends RequestDto
{
    /** @var string A workspace path (`reports/Plan.md`, `drivespaces/library/Brand/Logo.svg`) or a reference `drive/<id>` */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $path = '';
}
