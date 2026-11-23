<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListItem;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersions;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One file or folder with its path and, for a file, its versions. */
class DriveNodeGetResponseDto extends RestResponseDto
{
    /** @var DriveNode The file or folder */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveNode $driveNode;

    /** @var DriveNodeListItem|null The node as a file manager draws it (family, scan status, names, thumbnail) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveNodeListItem $item = null;

    /** @var string The path in its space */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $path;

    /** @var DriveNodes|null The folders above it, the root first and its own folder last (for breadcrumbs and deep links) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveNodes $ancestors = null;

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
