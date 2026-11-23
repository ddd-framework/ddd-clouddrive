<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One page of a folder (folders first, then files, by name), or with filters, another order or `recursive` one page
 * of the matching nodes (then `nextCursor` is an offset cursor).
 */
class DriveChildrenGetRequestDto extends RequestDto
{
    /** @var int The drive space */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveSpaceId;

    /** @var int|null The folder; omitted = the root */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $folderNodeId = null;

    /** @var string|null The `nextCursor` of the previous page */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $cursor = null;

    /** @var int|null Entries per page (default 100, at most 1000) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;

    /** @var bool|null Also system folders (`work/`) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $includeSystem = null;

    /** @var bool|null The whole space instead of one folder (for search and filters) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $recursive = null;

    /** @var string|null Words of the name (case and accents do not matter) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $query = null;

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
}
