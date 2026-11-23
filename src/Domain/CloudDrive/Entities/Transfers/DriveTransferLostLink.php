<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Transfers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A link of the application a file loses when it moves to another space (the target cannot hold it), as a
 * {@see \DDD\Domain\CloudDrive\Services\DriveSpaceTransferListener} reports it for the transfer's preview.
 */
class DriveTransferLostLink extends ValueObject
{
    /** @var int The file or folder that holds the link */
    public int $driveNodeId = 0;

    /** @var string What it links to, in the application's words (e.g. `project`) */
    public string $linkKind = '';

    /** @var string The linked record's key */
    public string $linkKey = '';

    /** @var string|null The linked record's name, for people */
    public ?string $label = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->driveNodeId . '_' . $this->linkKind . '_' . $this->linkKey);
    }
}
