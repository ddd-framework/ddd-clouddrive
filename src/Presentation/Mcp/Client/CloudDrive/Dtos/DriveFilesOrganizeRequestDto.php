<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `organizeDriveFiles`: move, rename, tag or describe one file or folder of a mounted space (in the own drive use mv and commit). */
class DriveFilesOrganizeRequestDto extends RequestDto
{
    /** @var string The file or folder: workspace path or `drive/<id>` */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $path = '';

    /** @var string|null The target folder in the same space, e.g. `drivespaces/library/Brand/Old` */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $moveTo = null;

    /** @var string|null The new name */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $rename = null;

    /** @var string[]|null The tags (replaces them; [] removes all) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $tags = null;

    /** @var string|null The description (replaces it; "" removes it) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $description = null;
}
