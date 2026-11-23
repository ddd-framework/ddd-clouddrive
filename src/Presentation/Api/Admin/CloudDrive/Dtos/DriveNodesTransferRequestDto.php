<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Files and folders of a space (at most 200) to move into another space. */
class DriveNodesTransferRequestDto extends RequestDto
{
    /** @var int The source space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var int[] The files and folders (folders move with their live content) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $driveNodeIds = [];

    /** @var int The target space (the write right is needed on both) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $targetDriveSpaceId = 0;

    /** @var int|null The target folder; omitted = the target's root */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $targetFolderNodeId = null;
}
