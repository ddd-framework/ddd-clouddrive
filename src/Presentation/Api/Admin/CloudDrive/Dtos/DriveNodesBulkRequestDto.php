<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One action on several files and folders of a space (at most 200), in one transaction. */
class DriveNodesBulkRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var int[] The files and folders */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $driveNodeIds = [];

    /** @var int|null Move only: the target folder; omitted = the root */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $targetFolderNodeId = null;
}
