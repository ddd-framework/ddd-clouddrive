<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `trashDriveFiles`: files or folders of a mounted space to the trash (restorable for 30 days). Only on the owner's request. */
class DriveFilesTrashRequestDto extends RequestDto
{
    /** @var string[] Workspace paths or `drive/<id>`, at most 50 */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $paths = [];
}
