<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveFileFamily;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeBulkResult;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodeListQuery;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;

/** What a file manager reads beside a node: its family, the scan status, the page cursor, bulk limits, restorability */
class DriveFileManagerFactsTest extends TestCase
{
    public function testTheFamilyComesFromTheTypeAndElseFromTheExtension(): void
    {
        self::assertSame(DriveFileFamily::FAMILY_PDF, DriveFileFamily::findFamily('application/pdf', 'x.bin'));
        self::assertSame(DriveFileFamily::FAMILY_IMAGE, DriveFileFamily::findFamily('image/png'));
        self::assertSame(DriveFileFamily::FAMILY_VECTOR, DriveFileFamily::findFamily('image/svg+xml'), 'an exact type wins over the image/ prefix');
        self::assertSame(DriveFileFamily::FAMILY_SPREADSHEET, DriveFileFamily::findFamily('text/csv; charset=utf-8'), 'csv is no plain text');
        self::assertSame(DriveFileFamily::FAMILY_TEXT, DriveFileFamily::findFamily('text/plain'));
        self::assertSame(DriveFileFamily::FAMILY_SPREADSHEET, DriveFileFamily::findFamily('application/octet-stream', 'Budget 2026.XLSX'));
        self::assertSame(DriveFileFamily::FAMILY_OTHER, DriveFileFamily::findFamily('application/octet-stream', 'notes'));
        self::assertSame('', DriveFileFamily::findExtension('.env'));
        self::assertNotContains('text/csv', DriveFileFamily::findContentTypesOfOtherFamilies(DriveFileFamily::FAMILY_SPREADSHEET));
        self::assertContains('text/csv', DriveFileFamily::findContentTypesOfOtherFamilies(DriveFileFamily::FAMILY_TEXT));
    }

    public function testTheScanStatusNamesTheBlobState(): void
    {
        $expectedStatuses = [
            DriveBlob::STATE_UPLOADING => DriveBlob::SCAN_STATUS_UPLOADING,
            DriveBlob::STATE_STAGED => DriveBlob::SCAN_STATUS_PENDING,
            DriveBlob::STATE_PENDING => DriveBlob::SCAN_STATUS_PENDING,
            DriveBlob::STATE_RELEASED => DriveBlob::SCAN_STATUS_CLEAN,
            DriveBlob::STATE_BLOCKED => DriveBlob::SCAN_STATUS_INFECTED,
            DriveBlob::STATE_SCAN_FAILED => DriveBlob::SCAN_STATUS_FAILED,
            DriveBlob::STATE_FAILED => DriveBlob::SCAN_STATUS_FAILED,
            DriveBlob::STATE_PURGING => DriveBlob::SCAN_STATUS_PURGING,
        ];
        foreach ($expectedStatuses as $blobState => $expectedStatus) {
            $driveBlob = new DriveBlob();
            $driveBlob->state = $blobState;
            self::assertSame($expectedStatus, $driveBlob->findScanStatus(), $blobState);
        }
    }

    public function testOnlyReleasedContentIsRestored(): void
    {
        $driveBlob = new DriveBlob();
        $driveBlob->state = DriveBlob::STATE_RELEASED;
        self::assertNull(DriveVersionsService::findRestoreRefusalReason($driveBlob));
        foreach ([DriveBlob::STATE_BLOCKED, DriveBlob::STATE_PENDING, DriveBlob::STATE_SCAN_FAILED, DriveBlob::STATE_PURGING] as $refusedState) {
            $driveBlob->state = $refusedState;
            self::assertNotNull(DriveVersionsService::findRestoreRefusalReason($driveBlob), $refusedState);
        }
        self::assertNotNull(DriveVersionsService::findRestoreRefusalReason(null));
    }

    public function testAPlainFolderListingKeepsTheNameCursor(): void
    {
        $driveNodeListQuery = new DriveNodeListQuery();
        self::assertTrue($driveNodeListQuery->isPlainFolderListing());
        $driveNodeListQuery->orderBy = DriveNodeListQuery::ORDER_BY_SIZE;
        self::assertFalse($driveNodeListQuery->isPlainFolderListing());
        $driveNodeListQuery = new DriveNodeListQuery();
        $driveNodeListQuery->createdFrom = Date::fromString('2026-01-01');
        self::assertFalse($driveNodeListQuery->isPlainFolderListing());
    }

    public function testFilteredListsPageByOffset(): void
    {
        self::assertSame('o:200', DriveNodesService::buildOffsetCursor(200));
        self::assertSame(200, DriveNodesService::parseOffsetCursor('o:200'));
        self::assertNull(DriveNodesService::parseOffsetCursor("n:FILE\tPlan.md"));
        self::assertNull(DriveNodesService::parseOffsetCursor('o:-1'));
        self::assertNull(DriveNodesService::parseOffsetCursor(null));
    }

    public function testBulkRequestsAreDistinctAndBounded(): void
    {
        self::assertSame([3, 5], DriveNodesService::normalizeBulkNodeIds([3, '5', 3, 0, -2]));
        $this->expectException(BadRequestException::class);
        DriveNodesService::normalizeBulkNodeIds(range(1, DriveNodeBulkResult::MAX_NODES + 1));
    }

    public function testAnEmptyBulkRequestIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        DriveNodesService::normalizeBulkNodeIds([0]);
    }

    public function testABulkResultNamesWhatItSkipped(): void
    {
        $driveNodeBulkResult = new DriveNodeBulkResult();
        $driveNodeBulkResult->addSkip(7, 'not in the trash');
        self::assertSame(1, $driveNodeBulkResult->skipped->count());
        self::assertSame('not in the trash', $driveNodeBulkResult->skipped->getElements()[array_key_first($driveNodeBulkResult->skipped->getElements())]->reason);
    }
}
