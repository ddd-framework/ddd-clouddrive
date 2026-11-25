<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Exceptions;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * One conversion of an Office file to PDF failed. Raised and caught inside the render
 * ({@see \DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService::renderRendition()}): the reason code goes into the
 * blob's failure marker (a reader sees its plain sentence), the message stays in the server log.
 */
class DriveOfficeRenditionFailedException extends InternalErrorException
{
    /** @var string One of {@see \DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService::FAILURE_REASONS}' keys */
    public string $reasonCode = '';

    /**
     * @param string $reasonCode what the reader is told (a code of the renditions service)
     * @param string $logDetail what the server log keeps (may quote the converter's output)
     */
    public static function forReason(string $reasonCode, string $logDetail): static
    {
        $driveOfficeRenditionFailedException = new static($logDetail);
        $driveOfficeRenditionFailedException->reasonCode = $reasonCode;
        return $driveOfficeRenditionFailedException;
    }
}
