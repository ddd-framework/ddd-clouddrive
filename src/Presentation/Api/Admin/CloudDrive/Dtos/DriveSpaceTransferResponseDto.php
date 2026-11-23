<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Transfers\DriveSpaceTransferResult;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What a move to another space did (or `isQueued` when it runs as a job). */
class DriveSpaceTransferResponseDto extends RestResponseDto
{
    /** @var DriveSpaceTransferResult Moved nodes (source id → target id) and the ones left with their reason */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveSpaceTransferResult $result;
}
