<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadReservation;

/** Where the runner sends the bytes of one saved change; a reservation whose content is known needs no upload */
class DriveStagedUpload extends ValueObject
{
    public string $path = '';

    public ?DriveUploadReservation $reservation = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->path);
    }
}
