<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Files and folders whose name contains the query (case and accents do not matter). */
class DriveSearchGetRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var string Words of the name */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $query = '';

    /** @var int|null The most hits (default 50, at most 200) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;

    /** @var string|null Files of one family: PDF, SPREADSHEET, DOCUMENT, PRESENTATION, TEXT, IMAGE, VECTOR, VIDEO, ARCHIVE */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $family = null;

    /** @var string|null Created on or after this day (`Y-m-d`) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $createdFrom = null;

    /** @var string|null Created on or before this day (`Y-m-d`) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $createdTo = null;

    /** @var bool|null Only what the caller created (uploaded) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $createdByMe = null;

    /** @var string|null `name` (default), `created` or `size`; folders always come first */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $orderBy = null;

    /** @var string|null `asc` (default) or `desc` */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $orderDirection = null;

    /** @var string|null The `nextCursor` of the previous page (only with filters or an order) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $cursor = null;
}
