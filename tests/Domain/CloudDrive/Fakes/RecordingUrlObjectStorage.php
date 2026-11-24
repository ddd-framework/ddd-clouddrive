<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;

/** A filesystem store that records the response type and disposition of every presigned GET it is asked for */
class RecordingUrlObjectStorage extends FilesystemObjectStorage
{
    public ?string $lastResponseContentType = null;

    public ?string $lastResponseContentDisposition = null;

    public function createTemporaryUrl(
        string $key,
        int $expiresInSeconds = 3600,
        ?string $responseContentType = null,
        ?string $responseContentDisposition = null
    ): string {
        $this->lastResponseContentType = $responseContentType;
        $this->lastResponseContentDisposition = $responseContentDisposition;
        return parent::createTemporaryUrl($key, $expiresInSeconds, $responseContentType, $responseContentDisposition);
    }
}
