<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A new folder. */
class DriveFolderCreateRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var int|null The parent folder; omitted = the root */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $parentNodeId = null;

    /** @var string The folder name (case-sensitive, no slash) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $name = '';
}
