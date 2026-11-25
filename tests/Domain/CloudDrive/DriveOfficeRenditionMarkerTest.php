<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRenditionMarker;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use PHPUnit\Framework\TestCase;

/** The markers next to a blob keep their stored JSON shape; an unreadable marker reads as empty */
class DriveOfficeRenditionMarkerTest extends TestCase
{
    public function testThePendingAndTheFailureMarkerKeepTheirStoredShape(): void
    {
        self::assertSame('{"requestedAt":1700000000}', DriveOfficeRenditionMarker::createPending(1700000000)->toStoredJson());
        self::assertSame(
            '{"reasonCode":"TIMEOUT","failedAt":1700000100}',
            DriveOfficeRenditionMarker::createFailure(DriveOfficeRenditionsService::FAILURE_TIMEOUT, 1700000100)->toStoredJson()
        );
        $failureMarker = DriveOfficeRenditionMarker::fromStoredJson('{"reasonCode":"TIMEOUT","failedAt":1700000100}');
        self::assertSame(DriveOfficeRenditionsService::FAILURE_TIMEOUT, $failureMarker->reasonCode);
        self::assertSame(1700000100, $failureMarker->failedAt);
        self::assertNull($failureMarker->requestedAt);
    }

    public function testAnUnreadableMarkerReadsAsEmpty(): void
    {
        foreach (['', 'not json', '[1,2]', '{"requestedAt":"soon","reasonCode":7}'] as $storedJson) {
            $driveOfficeRenditionMarker = DriveOfficeRenditionMarker::fromStoredJson($storedJson);
            self::assertNull($driveOfficeRenditionMarker->requestedAt, $storedJson);
            self::assertNull($driveOfficeRenditionMarker->failedAt, $storedJson);
            self::assertNull($driveOfficeRenditionMarker->reasonCode, $storedJson);
        }
    }
}
