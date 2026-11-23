<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersions;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One file or folder with its path and, for a file, its versions. */
class DriveNodeGetResponseDto extends RestResponseDto
{
    /** @var DriveNode The file or folder */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveNode $driveNode;

    /** @var string The path in its space */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $path;

    /** @var DriveFileVersions|null The versions, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveFileVersions $driveFileVersions = null;

    /** @var bool|null A completed upload found a newer version: nothing was written */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?bool $isConflict = null;

    /** @var string|null Why */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $conflictReason = null;
}
