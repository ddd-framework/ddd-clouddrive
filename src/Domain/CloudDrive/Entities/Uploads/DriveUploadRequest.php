<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Uploads;

use DDD\Domain\Base\Entities\ValueObject;

/** What a client announces before an upload: size and type, optionally the MD5 the store verifies and an idempotency key */
class DriveUploadRequest extends ValueObject
{
    public int $sizeBytes = 0;

    public ?string $contentType = null;

    /** @var string|null MD5 of the whole content (base64): the store refuses other bytes of a single PUT */
    public ?string $contentMd5 = null;

    /** @var string|null SHA-256 (hex) the client computed; content the space holds already is not uploaded again */
    public ?string $sha256 = null;

    /** @var string|null The file's name, when known: the type policy checks its extension at the reservation */
    public ?string $fileName = null;

    /** @var string|null A retried reserve with the same key gets the same blob */
    public ?string $idempotencyKey = null;
}
