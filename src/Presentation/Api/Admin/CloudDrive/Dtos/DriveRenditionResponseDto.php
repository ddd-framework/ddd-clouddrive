<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRendition;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Where the PDF rendition of an Office file stands. */
class DriveRenditionResponseDto extends RestResponseDto
{
    /** @var string READY (the grant opens the PDF), PENDING (it is being converted: ask again in a few seconds) or FAILED (see reason) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $state = DriveOfficeRendition::STATE_PENDING;

    /** @var string|null FAILED only: why there is no rendition, in plain words */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $reason = null;

    /** @var DriveContentGrant|null READY only: the short-lived inline URL of the PDF and its facts */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DriveContentGrant $grant = null;
}
