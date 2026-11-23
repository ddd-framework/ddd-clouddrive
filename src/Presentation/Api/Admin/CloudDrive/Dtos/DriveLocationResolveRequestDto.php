<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A pasted location to open: a path in the space, `drive/<id>`, `drivespaces/<mount>/…` or `/workspace/drive/…`. */
class DriveLocationResolveRequestDto extends RequestDto
{
    /** @var int The drive space a plain path belongs to */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var string The location (`/Templates/Typst/letter.typ`, `drive/812`, `drivespaces/library/Brand`, `/workspace/drive/Reports`) */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $path;
}
