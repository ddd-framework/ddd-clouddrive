<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Services\DriveMaintenanceService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\InMemoryAccessEventStore;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RetentionDriveMaintenanceService;
use PHPUnit\Framework\TestCase;

/** accessEventRetentionDays: unlimited by default, a set value deletes older accesses where they are stored */
class DriveAccessEventRetentionTest extends TestCase
{
    public function testRetentionIsUnlimitedUnlessAPositiveNumberIsSet(): void
    {
        foreach ([0, '0', null, -5, 'never', ''] as $unlimitedValue) {
            self::assertNull(DriveMaintenanceService::resolveAccessEventRetentionDays($unlimitedValue), var_export($unlimitedValue, true));
        }
        self::assertSame(365, DriveMaintenanceService::resolveAccessEventRetentionDays(365));
        self::assertSame(30, DriveMaintenanceService::resolveAccessEventRetentionDays('30'));
    }

    public function testUnlimitedRetentionDeletesNothing(): void
    {
        $inMemoryAccessEventStore = $this->buildStore();
        $resultLines = (new RetentionDriveMaintenanceService(null, $inMemoryAccessEventStore))->purgeAccessEventsBeyondRetention(false);

        self::assertCount(1, $resultLines);
        self::assertStringContainsString('skipped', $resultLines[0]);
        self::assertCount(3, $inMemoryAccessEventStore->changeEventCreatedAtById);
        self::assertCount(3, $inMemoryAccessEventStore->activityEventCreatedAtById);
    }

    public function testOlderAccessesAreDeletedInBothTablesWithTheirContexts(): void
    {
        $inMemoryAccessEventStore = $this->buildStore();
        $resultLines = (new RetentionDriveMaintenanceService(365, $inMemoryAccessEventStore))->purgeAccessEventsBeyondRetention(false);

        self::assertSame([3], array_keys($inMemoryAccessEventStore->changeEventCreatedAtById));
        self::assertSame([30], array_keys($inMemoryAccessEventStore->activityEventCreatedAtById));
        self::assertSame([1, 2], $inMemoryAccessEventStore->deletedContextChangeEventIds);
        self::assertStringContainsString('deleted 4 access event(s) older than 365 days (2 in the change feed, 2 in the activity table)', $resultLines[0]);
    }

    public function testADryRunCountsAndDeletesNothing(): void
    {
        $inMemoryAccessEventStore = $this->buildStore();
        $resultLines = (new RetentionDriveMaintenanceService(365, $inMemoryAccessEventStore))->purgeAccessEventsBeyondRetention(true);

        self::assertStringContainsString('would delete 4 access event(s)', $resultLines[0]);
        self::assertCount(3, $inMemoryAccessEventStore->changeEventCreatedAtById);
        self::assertSame([], $inMemoryAccessEventStore->deletedContextChangeEventIds);
    }

    public function testNothingOldEnoughAnswersNoLine(): void
    {
        $inMemoryAccessEventStore = new InMemoryAccessEventStore();
        $inMemoryAccessEventStore->changeEventCreatedAtById = [7 => time()];
        self::assertSame([], (new RetentionDriveMaintenanceService(365, $inMemoryAccessEventStore))->purgeAccessEventsBeyondRetention(false));
    }

    protected function buildStore(): InMemoryAccessEventStore
    {
        $inMemoryAccessEventStore = new InMemoryAccessEventStore();
        $twoYearsAgo = time() - 730 * 86400;
        $inMemoryAccessEventStore->changeEventCreatedAtById = [1 => $twoYearsAgo, 2 => $twoYearsAgo, 3 => time() - 86400];
        $inMemoryAccessEventStore->activityEventCreatedAtById = [10 => $twoYearsAgo, 20 => $twoYearsAgo, 30 => time()];
        return $inMemoryAccessEventStore;
    }
}
