<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `setDriveSpaceSettings`: the audience's write lock, quota and largest file of a mounted space (manage right). */
class DriveSpaceSettingsSetRequestDto extends RequestDto
{
    /** @var string A mount name as driveStatus lists it (e.g. `space-12`) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $space = '';

    /** @var bool|null true: the people the space is shared with only read; false: they write again */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $isAudienceWriteLocked = null;

    /** @var int|null The quota in megabytes (1 GB = 1024); 0 returns to the default of the space's kind */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $quotaMegabytes = null;

    /** @var int|null The largest file in megabytes; 0 returns to the default of the space's kind */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $maxFileSizeMegabytes = null;
}
