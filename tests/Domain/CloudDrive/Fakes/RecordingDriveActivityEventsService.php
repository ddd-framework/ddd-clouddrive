<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveActivityEventsService;

/** The activity service keeping what it would store, without a table and without deduplication */
class RecordingDriveActivityEventsService extends DriveActivityEventsService
{
    /** @var DriveActivityEvent[] */
    public array $storedActivityEvents = [];

    public function __construct()
    {
    }

    public function isActivityTableAvailable(): bool
    {
        return true;
    }

    public function findClientIpOfCurrentRequest(): ?string
    {
        return null;
    }

    protected function hasRecentAccess(DriveNode $driveNode, ?int $driveFileVersionId, string $eventType, ?int $actorAccountId): bool
    {
        return false;
    }

    protected function findActingAccountId(): ?int
    {
        return 5;
    }

    protected function storeActivityEvent(DriveActivityEvent $driveActivityEvent, ?DriveNode $driveNode): ?DriveActivityEvent
    {
        $this->storedActivityEvents[] = $driveActivityEvent;
        return $driveActivityEvent;
    }
}
