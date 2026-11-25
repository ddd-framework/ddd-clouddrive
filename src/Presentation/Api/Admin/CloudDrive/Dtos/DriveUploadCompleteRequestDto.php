<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\CloudDrive\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Completes an upload into a file: a new file, or a new version of the file of that name. */
class DriveUploadCompleteRequestDto extends RequestDto
{
    /** @var int The upload (blob) id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $driveBlobId;

    /** @var int|null The target folder; omitted = the root */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $folderNodeId = null;

    /** @var string The file name */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $name = '';

    /** @var string|null The version comment */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $comment = null;

    /** @var int|null The version the upload replaces (compare and swap); omitted for a new file */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $expectedLatestVersionId = null;

    /** @var bool|null Write on top of whatever version is there */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $replaceExisting = null;

    /**
     * @var int|null The agent conversation this write happens beside (the file is open in its canvas): the new version
     * is noted there for the agent's next turn. Only a conversation the caller may read gets a note; ignored without
     * mgamadeus/ddd-agents.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentConversationId = null;
}
