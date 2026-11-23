<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveActivityEventsService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** The activity table's access events in memory */
class InMemoryAccessEventsDriveActivityEventsService extends DriveActivityEventsService
{
    public function __construct(public InMemoryAccessEventStore $inMemoryAccessEventStore)
    {
    }

    public function findAccessEventIdsCreatedBefore(DateTime $createdBefore, int $limit): array
    {
        $matchingIds = array_keys(array_filter($this->inMemoryAccessEventStore->activityEventCreatedAtById, fn (int $createdAt) => $createdAt < $createdBefore->getTimestamp()));
        sort($matchingIds);
        return array_slice($matchingIds, 0, $limit);
    }

    public function countAccessEventsCreatedBefore(DateTime $createdBefore): int
    {
        return count($this->findAccessEventIdsCreatedBefore($createdBefore, PHP_INT_MAX));
    }

    public function deleteAccessEventsByIds(array $driveActivityEventIds): int
    {
        $deletedCount = 0;
        foreach ($driveActivityEventIds as $driveActivityEventId) {
            if (isset($this->inMemoryAccessEventStore->activityEventCreatedAtById[$driveActivityEventId])) {
                unset($this->inMemoryAccessEventStore->activityEventCreatedAtById[$driveActivityEventId]);
                $deletedCount++;
            }
        }
        return $deletedCount;
    }

    public function deleteContextsOfChangeEvents(array $driveChangeEventIds): int
    {
        array_push($this->inMemoryAccessEventStore->deletedContextChangeEventIds, ...$driveChangeEventIds);
        return count($driveChangeEventIds);
    }
}
