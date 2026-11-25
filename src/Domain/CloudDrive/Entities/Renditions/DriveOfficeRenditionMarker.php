<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Renditions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A marker object stored next to a blob for its PDF rendition
 * ({@see \DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService}): the pending marker (`requestedAt`) or the
 * failure marker (`failedAt`, `reasonCode`). Stored as JSON; an unreadable marker reads as empty (no time: a pending
 * marker counts as lost, a failure as past its retry time).
 */
class DriveOfficeRenditionMarker extends ValueObject
{
    /** @var int|null Pending marker: when the conversion was dispatched (unix seconds) */
    public ?int $requestedAt = null;

    /** @var int|null Failure marker: when the conversion failed (unix seconds) */
    public ?int $failedAt = null;

    /** @var string|null Failure marker: the reason code (a key of DriveOfficeRenditionsService::FAILURE_REASONS) */
    public ?string $reasonCode = null;

    /** The marker of a stored JSON text; an unreadable or foreign text gives an empty marker */
    public static function fromStoredJson(string $storedJson): static
    {
        $driveOfficeRenditionMarker = new static();
        $decodedMarker = json_decode($storedJson, true);
        if (!is_array($decodedMarker)) {
            return $driveOfficeRenditionMarker;
        }
        $driveOfficeRenditionMarker->requestedAt = is_numeric($decodedMarker['requestedAt'] ?? null) ? (int)$decodedMarker['requestedAt'] : null;
        $driveOfficeRenditionMarker->failedAt = is_numeric($decodedMarker['failedAt'] ?? null) ? (int)$decodedMarker['failedAt'] : null;
        $driveOfficeRenditionMarker->reasonCode = is_string($decodedMarker['reasonCode'] ?? null) ? $decodedMarker['reasonCode'] : null;
        return $driveOfficeRenditionMarker;
    }

    /** The pending marker of a dispatch at `$requestedAt` */
    public static function createPending(int $requestedAt): static
    {
        $driveOfficeRenditionMarker = new static();
        $driveOfficeRenditionMarker->requestedAt = $requestedAt;
        return $driveOfficeRenditionMarker;
    }

    /** The failure marker of a conversion that failed at `$failedAt` for `$reasonCode` */
    public static function createFailure(string $reasonCode, int $failedAt): static
    {
        $driveOfficeRenditionMarker = new static();
        $driveOfficeRenditionMarker->reasonCode = $reasonCode;
        $driveOfficeRenditionMarker->failedAt = $failedAt;
        return $driveOfficeRenditionMarker;
    }

    /** The JSON stored in the object store (only the fields the marker carries) */
    public function toStoredJson(): string
    {
        return json_encode(array_filter(
            ['requestedAt' => $this->requestedAt, 'reasonCode' => $this->reasonCode, 'failedAt' => $this->failedAt],
            static fn(mixed $fieldValue): bool => $fieldValue !== null
        ), JSON_THROW_ON_ERROR);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
