<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A short-lived URL of a file's content. */
class DriveContentGetRequestDto extends RequestDto
{
    /** @var int The file */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveNodeId;

    /** @var string|null DOWNLOAD (default: the original as an attachment, logged as DOWNLOADED), INLINE (a preview, logged as VIEWED), THUMBNAIL (a list's picture, not logged) or RENDITION (the stored PDF rendition of an Office file, logged as VIEWED; 404 while there is none) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $purpose = null;

    /** @var int|null An older version; omitted = the current one */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $versionId = null;
}
