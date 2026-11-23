<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Moves and/or renames a file or folder inside its space. */
class DriveNodeMoveRequestDto extends RequestDto
{
    /** @var int The file or folder */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveNodeId;

    /** @var int|null The new folder; omitted = stays */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $targetFolderNodeId = null;

    /** @var string|null The new name; omitted = stays */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $name = null;
}
