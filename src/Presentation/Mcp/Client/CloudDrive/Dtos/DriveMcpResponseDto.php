<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\CloudDrive\Dtos;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer of the drive tools: lines for the model (one per file, change or mount) and a note */
class DriveMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string[] The answer's lines */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $lines = [];

    /** @var string|null What happened, for the model */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /** @param string[] $lines */
    public static function withLines(array $lines, ?string $note = null): static
    {
        $driveMcpResponseDto = new static();
        $driveMcpResponseDto->lines = array_values($lines);
        $driveMcpResponseDto->note = $note;
        return $driveMcpResponseDto;
    }

    public function toLlmMarkdown(): string
    {
        $markdownLines = $this->note !== null && $this->note !== '' ? [$this->note] : [];
        return implode("\n", [...$markdownLines, ...$this->lines]);
    }
}
