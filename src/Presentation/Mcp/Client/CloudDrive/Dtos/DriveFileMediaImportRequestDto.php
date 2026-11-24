<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `importDriveFileToMediaLibrary`: one image of the drive taken into the media library. */
class DriveFileMediaImportRequestDto extends RequestDto
{
    /** @var string The image: workspace path or `drive/<id>` */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $path = '';

    /** @var string|null The media item's title; the file name when empty */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;

    /** @var string|null What the picture shows */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $description = null;
}
