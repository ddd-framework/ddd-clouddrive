<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItems;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Every live folder of a space, the root included; `parentNodeId` builds the tree. */
class DriveFoldersGetResponseDto extends RestResponseDto
{
    /** @var DriveNodeListItems The folders, by name */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveNodeListItems $items;

    /** @var bool More folders exist than one call answers (5000) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isTruncated = false;
}
