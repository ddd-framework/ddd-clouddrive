<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The PDF rendition of an Office file. */
class DriveRenditionGetRequestDto extends RequestDto
{
    /** @var int The file */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveNodeId;

    /** @var int|null An older version; omitted = the current one */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $versionId = null;
}
