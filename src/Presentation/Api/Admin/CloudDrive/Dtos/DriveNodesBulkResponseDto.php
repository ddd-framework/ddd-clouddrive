<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkResult;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What a bulk action did. */
class DriveNodesBulkResponseDto extends RestResponseDto
{
    /** @var DriveNodeBulkResult Counts and the skipped nodes with their reasons */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveNodeBulkResult $result;
}
