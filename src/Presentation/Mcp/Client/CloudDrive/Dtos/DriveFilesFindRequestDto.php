<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `findDriveFiles`: search the own drive and the mounts by name and tags, without a download. */
class DriveFilesFindRequestDto extends RequestDto
{
    /** @var string Words of the name (case and accents do not matter), or `#tag` */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $query = '';

    /** @var string|null Only one space: `own` or a mount name (`library`) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $space = null;

    /** @var int|null The most hits (default 30, at most 100) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
