<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

/** Access-event ids by table for the retention test: created timestamps, deletions recorded */
class InMemoryAccessEventStore
{
    /** @var int[] change-table access event id => created unix time */
    public array $changeEventCreatedAtById = [];

    /** @var int[] activity-table access event id => created unix time */
    public array $activityEventCreatedAtById = [];

    /** @var int[] change ids whose context rows were deleted */
    public array $deletedContextChangeEventIds = [];
}
