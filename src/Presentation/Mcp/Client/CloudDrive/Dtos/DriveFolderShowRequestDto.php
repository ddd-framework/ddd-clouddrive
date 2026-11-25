<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `showDriveFolder`: a folder the owner browses in the canvas beside the chat. */
class DriveFolderShowRequestDto extends RequestDto
{
    /** @var string|null The folder: workspace path (`reports`, `drivespaces/library/Brand`) or `drive/<id>`; omitted = the own drive's root */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $path = null;

    /** @var string|null The card's headline instead of the folder's name */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;
}
