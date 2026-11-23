<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceListItem;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceListItems;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceQuota;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaceUsage;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The space picker's group code and order, the split of the used bytes, and the states a refused version shows */
class DriveSpaceListAndUsageTest extends TestCase
{
    public function testTheUsedBytesSplitIntoReleasedPartsAndThePendingRest(): void
    {
        $driveSpacesService = new DriveSpacesService();
        $driveSpaceQuota = new DriveSpaceQuota();
        $driveSpaceQuota->usedBytes = 1000;
        $driveSpaceUsage = new DriveSpaceUsage();
        $driveSpaceUsage->currentVersionBytes = 500;
        $driveSpaceUsage->olderVersionBytes = 200;
        $driveSpaceUsage->trashedBytes = 100;
        $driveSpacesService->applySpaceUsageToQuota($driveSpaceQuota, $driveSpaceUsage);
        self::assertSame(200, $driveSpaceQuota->pendingBytes);
        self::assertSame(1000, $driveSpaceQuota->currentVersionBytes + $driveSpaceQuota->olderVersionBytes + $driveSpaceQuota->trashedBytes + $driveSpaceQuota->pendingBytes);

        $driveSpaceQuota->usedBytes = 700;
        $driveSpacesService->applySpaceUsageToQuota($driveSpaceQuota, $driveSpaceUsage);
        self::assertSame(0, $driveSpaceQuota->pendingBytes, 'a lagging counter never gives a negative rest');

        $driveSpacesService->applySpaceUsageToQuota($driveSpaceQuota, null);
        self::assertSame(700, $driveSpaceQuota->pendingBytes, 'without released blobs everything is pending');
    }

    public function testTheGroupCodeIsTheKindAndTheListIsOrderedByGroupThenName(): void
    {
        $driveSpacesService = new DriveSpacesService();
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->spaceKind = 'library';
        self::assertSame('LIBRARY', $driveSpacesService->buildSpaceDisplayGroup($driveSpace));
        $driveSpace->spaceKind = '';
        self::assertNull($driveSpacesService->buildSpaceDisplayGroup($driveSpace));

        $driveSpaceListItems = new DriveSpaceListItems();
        foreach ([[1, 'B', 'zeta'], [2, null, 'alpha'], [3, 'A', 'beta'], [4, 'A', 'Alpha'], [5, 'B', 'alpha']] as [$id, $displayGroup, $displayName]) {
            $driveSpaceListItem = new DriveSpaceListItem();
            $driveSpaceListItem->id = $id;
            $driveSpaceListItem->displayGroup = $displayGroup;
            $driveSpaceListItem->displayName = $displayName;
            $driveSpaceListItems->add($driveSpaceListItem);
        }
        $driveSpaceListItems->sort([$driveSpacesService, 'compareSpaceListItems']);
        $orderedIds = [];
        foreach ($driveSpaceListItems->getElements() as $driveSpaceListItem) {
            $orderedIds[] = $driveSpaceListItem->id;
        }
        self::assertSame([4, 3, 5, 1, 2], $orderedIds, 'group code, then name case-insensitively; no group last');
    }

    public function testAPolicyRefusalIsBlockedAndAFindingInfected(): void
    {
        $driveBlob = new DriveBlob();
        $driveBlob->state = DriveBlob::STATE_BLOCKED;
        $driveBlob->stateReason = DriveBlob::STATE_REASON_TYPE_POLICY_PREFIX . 'the content is no PDF';
        self::assertSame(DriveBlob::SCAN_STATUS_BLOCKED, $driveBlob->findScanStatus());
        $driveBlob->stateReason = 'malware: Eicar-Signature FOUND';
        self::assertSame(DriveBlob::SCAN_STATUS_INFECTED, $driveBlob->findScanStatus());
        $driveBlob->stateReason = null;
        self::assertSame(DriveBlob::SCAN_STATUS_INFECTED, $driveBlob->findScanStatus());
    }

    public function testOnlyAFailedScanMatchesTheRepairPrefix(): void
    {
        self::assertStringStartsWith(DriveBlob::STATE_REASON_SCAN_FAILED_PREFIX, 'scan failed (exit 2): LibClamAV Error: cli_loaddbdir(): No supported database files found');
        self::assertStringStartsNotWith(DriveBlob::STATE_REASON_SCAN_FAILED_PREFIX, 'malware: Eicar-Signature FOUND');
        self::assertStringStartsNotWith(DriveBlob::STATE_REASON_SCAN_FAILED_PREFIX, DriveBlob::STATE_REASON_TYPE_POLICY_PREFIX . 'program');
    }
}
