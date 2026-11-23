<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Transfers\DriveSpaceTransferPreview;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What a move to another space would do. */
class DriveSpaceTransferPreviewResponseDto extends RestResponseDto
{
    /** @var DriveSpaceTransferPreview The preview: counts, quota, taken names, refused files, lost links */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DriveSpaceTransferPreview $preview;
}
