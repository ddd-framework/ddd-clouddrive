<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `showDriveLocation`: a link the owner clicks to open a folder or a search in the drive screen. */
class DriveLocationShowRequestDto extends RequestDto
{
    /** @var string|null A folder: workspace path (`reports`, `drivespaces/library/Brand`) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $path = null;

    /** @var string|null A search in the drive screen instead of a folder */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $query = null;

    /** @var string|null The link text */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;
}
