<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Content;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a content URL grants, signed by the server ({@see \DDD\Domain\CloudDrive\Services\DriveContentTokensService}):
 * one version of one file, for one purpose, for the account and audience that asked, until `expiresAt`. The rights
 * and the scan state are checked again on every fetch; the token only says who asked for what.
 */
class DriveContentToken extends ValueObject
{
    public int $driveNodeId = 0;

    public int $driveFileVersionId = 0;

    /** @var string One of {@see DriveContentGrant::PEOPLE_PURPOSES} */
    public string $purpose = DriveContentGrant::PURPOSE_DOWNLOAD;

    /** @var int|null The account that asked; null = no signed-in account (a command, a job) */
    public ?int $accountId = null;

    /** @var string The drive audience the grant was made for */
    public string $audience = '';

    /** @var int|null The account an agent acted for when it asked (recorded with the access) */
    public ?int $onBehalfOfAccountId = null;

    /** @var int Unix time after which the token is refused */
    public int $expiresAt = 0;

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt < ($now ?? time());
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->driveNodeId . '-' . $this->driveFileVersionId . '-' . $this->purpose . '-' . $this->expiresAt);
    }
}
