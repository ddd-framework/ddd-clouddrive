<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Files;

use DDD\Domain\Base\Entities\ValueObject;

/** Test stand-in for a sink's answer in ddd-agent-browser ≥ 0.2.0 */
class BrowserStoredFile extends ValueObject
{
    public ?string $path = null;
    public ?string $contentType = null;
    public ?int $sizeBytes = null;
    public ?string $refusalReason = null;

    public function isStored(): bool
    {
        return $this->path !== null && $this->refusalReason === null;
    }

    public static function refused(string $refusalReason): static
    {
        $browserStoredFile = new static();
        $browserStoredFile->refusalReason = $refusalReason;
        return $browserStoredFile;
    }
}
