<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveChangesService;

/** A host with impersonation: the staff member acts, the impersonated account is acted for */
class ImpersonatingDriveChangesService extends DriveChangesService
{
    public function __construct(public ?int $actingAccountId, public ?int $onBehalfOfAccountId)
    {
    }

    public function findActingAccountIdOfCurrentCaller(): ?int
    {
        return $this->actingAccountId;
    }

    public function findOnBehalfOfAccountIdOfCurrentCaller(): ?int
    {
        return $this->onBehalfOfAccountId;
    }
}
