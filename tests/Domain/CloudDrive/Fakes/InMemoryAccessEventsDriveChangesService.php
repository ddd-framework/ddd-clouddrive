<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** The change table's access events in memory */
class InMemoryAccessEventsDriveChangesService extends DriveChangesService
{
    public function __construct(public InMemoryAccessEventStore $inMemoryAccessEventStore)
    {
    }

    public function findAccessEventIdsCreatedBefore(DateTime $createdBefore, int $limit): array
    {
        $matchingIds = array_keys(array_filter($this->inMemoryAccessEventStore->changeEventCreatedAtById, fn (int $createdAt) => $createdAt < $createdBefore->getTimestamp()));
        sort($matchingIds);
        return array_slice($matchingIds, 0, $limit);
    }

    public function countAccessEventsCreatedBefore(DateTime $createdBefore): int
    {
        return count($this->findAccessEventIdsCreatedBefore($createdBefore, PHP_INT_MAX));
    }

    public function deleteAccessEventsByIds(array $driveChangeEventIds): int
    {
        $deletedCount = 0;
        foreach ($driveChangeEventIds as $driveChangeEventId) {
            if (isset($this->inMemoryAccessEventStore->changeEventCreatedAtById[$driveChangeEventId])) {
                unset($this->inMemoryAccessEventStore->changeEventCreatedAtById[$driveChangeEventId]);
                $deletedCount++;
            }
        }
        return $deletedCount;
    }
}
