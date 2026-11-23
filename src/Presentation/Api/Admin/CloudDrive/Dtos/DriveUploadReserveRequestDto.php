<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Reserves an upload: size and type of the file; answers one presigned PUT or the parts of a multipart upload. */
class DriveUploadReserveRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var int The size in bytes */
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $sizeBytes = 0;

    /** @var string|null The MIME type */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $contentType = null;

    /** @var string|null MD5 of the content (base64); the store refuses other bytes of a single PUT */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $contentMd5 = null;

    /** @var string|null SHA-256 (hex); content the space holds already needs no upload */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $sha256 = null;

    /** @var string|null The file's name: the space's type policy checks its extension before any byte is sent */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $fileName = null;

    /** @var string|null A retried reserve with the same key answers the same upload */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $idempotencyKey = null;
}
