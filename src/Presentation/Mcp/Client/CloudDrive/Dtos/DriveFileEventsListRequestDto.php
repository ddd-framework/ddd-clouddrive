<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `listDriveFileEvents`: who did what with one file or folder, newest first. */
class DriveFileEventsListRequestDto extends RequestDto
{
    /** @var string The file or folder: workspace path or `drive/<id>` */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $path = '';

    /** @var string|null `changes` (writes, moves, trash) or `accesses` (views, downloads, agent reads); both when empty */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $eventGroup = null;

    /** @var int|null At most this many events (default 30, at most 100) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
