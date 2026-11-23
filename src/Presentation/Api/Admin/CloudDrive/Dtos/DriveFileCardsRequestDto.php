<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** File cards for references (`drive/<id>`), e.g. of a `DriveFiles` widget. */
class DriveFileCardsRequestDto extends RequestDto
{
    /** @var string[] The references, at most 100 */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $references = [];
}
