<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Files;

use DDD\Domain\Base\Entities\ValueObject;

/** Test stand-in for the file handed to a sink by ddd-agent-browser ≥ 0.2.0 */
class BrowserFileToStore extends ValueObject
{
    public ?string $fileName = null;
    public ?string $targetFolder = null;
    public ?string $contentType = null;
    public ?string $kind = null;
    public ?string $sourceUrl = null;
    public ?string $pageUrl = null;
    public bool $isUntrusted = true;
    protected ?string $bytes = null;

    public function setBytes(string $bytes): void
    {
        $this->bytes = $bytes;
    }

    public function getBytes(): string
    {
        return (string)$this->bytes;
    }

    public function getSizeBytes(): int
    {
        return strlen((string)$this->bytes);
    }
}
