<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Replaces tags and/or the description. */
class DriveNodeMetadataRequestDto extends RequestDto
{
    /** @var int The file or folder */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveNodeId;

    /** @var string[]|null The tags; [] removes all */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $tags = null;

    /** @var string|null The description; "" removes it */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $description = null;
}
