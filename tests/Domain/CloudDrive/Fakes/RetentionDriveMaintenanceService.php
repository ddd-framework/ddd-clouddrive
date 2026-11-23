<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveActivityEventsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveMaintenanceService;

/** The maintenance with a set retention and in-memory event tables */
class RetentionDriveMaintenanceService extends DriveMaintenanceService
{
    public function __construct(public ?int $accessEventRetentionDays, public InMemoryAccessEventStore $inMemoryAccessEventStore)
    {
    }

    public function getAccessEventRetentionDays(): ?int
    {
        return $this->accessEventRetentionDays;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        return new InMemoryAccessEventsDriveChangesService($this->inMemoryAccessEventStore);
    }

    protected function getDriveActivityEventsService(): DriveActivityEventsService
    {
        return new InMemoryAccessEventsDriveActivityEventsService($this->inMemoryAccessEventStore);
    }
}
