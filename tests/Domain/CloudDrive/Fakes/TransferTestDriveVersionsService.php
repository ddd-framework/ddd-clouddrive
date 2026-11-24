<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;

/** Versions of the transfer tests, in memory */
class TransferTestDriveVersionsService extends DriveVersionsService
{
    /** @var DriveFileVersion[] Versions by id */
    public array $versionsById = [];

    public function __construct()
    {
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->versionsById[(int)$entityId] ?? null;
    }
}
