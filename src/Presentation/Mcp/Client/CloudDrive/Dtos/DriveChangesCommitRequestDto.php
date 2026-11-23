<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `commitDriveChanges`: the saved changes of the own drive become versions with the message as comment. */
class DriveChangesCommitRequestDto extends RequestDto
{
    /** @var string The version comment, one line, e.g. "Zuordnung der Gewinncodes" */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $message = '';

    /** @var string[] Only these paths (default: every change of this conversation) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $paths = [];

    /** @var string[] Decisions on conflicted paths: `<path>=mine` (commit on top), `<path>=theirs` (drop yours), `<path>=both:<new name>` (yours under another name) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $resolve = [];
}
