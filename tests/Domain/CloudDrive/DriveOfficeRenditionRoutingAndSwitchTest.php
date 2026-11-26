<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRendition;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionMessage;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\CloudDrive\Services\DriveSvgSanitizer;
use DDD\Domain\CloudDrive\Services\DriveSvgSanitizersService;
use DDD\Domain\Common\Services\WorkerTransportsService;

/**
 * Two host settings of the rendition: the guard that keeps a conversion out of a web request (the message must be
 * routed to a transport a worker consumes) and the switch for derived objects
 * ({@see DriveBlobsService::isDerivedObjectWriteAllowed()}; a host's override decides for the rendition and the SVG copy).
 */
class DriveOfficeRenditionRoutingAndSwitchTest extends DriveOfficeRenditionTestCase
{
    public const string SVG_CONTENT_TYPE = 'image/svg+xml';

    public const string SANITIZED_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>';

    protected function routeRenditionMessage(string $messageType, string $transportName, string $dsn): void
    {
        $this->driveOfficeRenditionsService->testWorkerTransportsService = new WorkerTransportsService(
            receiverNames: [$transportName, 'failed'],
            failureTransportNames: ['failed'],
            transportDsnsByName: [$transportName => $dsn, 'failed' => 'doctrine://default?queue_name=failed'],
            transportNamesByMessageClass: [$messageType => $transportName]
        );
    }

    protected function assertRefusedAsNotRoutedAndNothingWritten(DriveOfficeRendition $driveOfficeRendition): void
    {
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $driveOfficeRendition->state);
        self::assertSame(DriveOfficeRenditionsService::findFailureReason(DriveOfficeRenditionsService::FAILURE_NOT_ROUTED_TO_WORKER), $driveOfficeRendition->reason);
        self::assertNull($driveOfficeRendition->grant);
        self::assertSame([], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'nothing dispatched');
        self::assertSame(0, $this->driveOfficeRenditionsService->conversionCount, 'nothing converted inside the request');
        foreach (DriveOfficeRenditionsService::buildDerivedStorageKeys(self::BLOB_STORAGE_KEY) as $derivedStorageKey) {
            self::assertFalse($this->objectStorage->objectExists($derivedStorageKey), $derivedStorageKey);
        }
    }

    public function testAWebRequestNeverConvertsWhenTheMessageIsRoutedNowhere(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->isWebRequestForTest = true;
        // a host without routing: Messenger would handle the message inside this request
        $this->driveOfficeRenditionsService->isRenderedOnDispatch = true;

        $this->assertRefusedAsNotRoutedAndNothingWritten($this->driveOfficeRenditionsService->requestRendition($driveNode));
        $this->assertRefusedAsNotRoutedAndNothingWritten($this->driveOfficeRenditionsService->requestRendition($driveNode));

        // one warning per process, naming the message class and the transport it belongs on
        self::assertCount(1, $this->driveOfficeRenditionsService->loggedRenditionRoutingProblems);
        self::assertStringContainsString(DriveOfficeRenditionMessage::class, $this->driveOfficeRenditionsService->loggedRenditionRoutingProblems[0]);
        self::assertStringContainsString('drive_render', $this->driveOfficeRenditionsService->loggedRenditionRoutingProblems[0]);
    }

    public function testAWebRequestNeverConvertsWhenTheMessageIsRoutedToASynchronousTransport(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->isWebRequestForTest = true;
        $this->driveOfficeRenditionsService->isRenderedOnDispatch = true;
        $this->routeRenditionMessage(DriveOfficeRenditionMessage::class, 'drive_render', 'sync://');
        $this->assertRefusedAsNotRoutedAndNothingWritten($this->driveOfficeRenditionsService->requestRendition($driveNode));

        $this->routeRenditionMessage(DriveOfficeRenditionMessage::class, 'drive_render', 'in-memory://');
        $this->assertRefusedAsNotRoutedAndNothingWritten($this->driveOfficeRenditionsService->requestRendition($driveNode));

        // a routing entry naming a transport that does not exist sends nowhere either
        $this->driveOfficeRenditionsService->testWorkerTransportsService = new WorkerTransportsService(
            transportNamesByMessageClass: [DriveOfficeRenditionMessage::class => 'drive_render']
        );
        $this->assertRefusedAsNotRoutedAndNothingWritten($this->driveOfficeRenditionsService->requestRendition($driveNode));
    }

    public function testAWebRequestDispatchesWhenTheMessageIsRoutedToAWorker(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->isWebRequestForTest = true;
        $this->routeRenditionMessage(DriveOfficeRenditionMessage::class, 'drive_render', 'amqp://broker/%2f/drive_render');

        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        self::assertSame([70], $this->driveOfficeRenditionsService->dispatchedVersionIds);
        self::assertSame([], $this->driveOfficeRenditionsService->loggedRenditionRoutingProblems);
        self::assertNull($this->driveOfficeRenditionsService->findRenditionRoutingProblem());
    }

    public function testRoutingByAParentClassOrTheWildcardCountsAsMessengerMatchesIt(): void
    {
        foreach ([AppMessage::class, DriveOfficeRenditionsService::ROUTING_WILDCARD] as $messageType) {
            $this->routeRenditionMessage($messageType, 'async', 'doctrine://default?queue_name=async');
            self::assertTrue($this->driveOfficeRenditionsService->isRenditionMessageRoutedToWorker(), $messageType);
        }
        $this->routeRenditionMessage(DriveContentGrant::class, 'async', 'doctrine://default?queue_name=async');
        self::assertFalse($this->driveOfficeRenditionsService->isRenditionMessageRoutedToWorker(), 'another class routes nothing of the rendition');
    }

    public function testAStoredRenditionIsStillServedToAWebRequestWithoutRouting(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        // made by a console command or by a worker of another setup
        $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);
        $this->driveOfficeRenditionsService->isWebRequestForTest = true;

        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_READY, $driveOfficeRendition->state);
        self::assertInstanceOf(DriveContentGrant::class, $driveOfficeRendition->grant);
        self::assertSame([], $this->driveOfficeRenditionsService->loggedRenditionRoutingProblems);
    }

    public function testTheCommandLineStillConvertsInsideTheProcessWithoutRouting(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->isRenderedOnDispatch = true;

        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_READY, $driveOfficeRendition->state);
        self::assertSame(1, $this->driveOfficeRenditionsService->conversionCount);
        self::assertSame([], $this->driveOfficeRenditionsService->loggedRenditionRoutingProblems);
    }

    public function testTheRoutingProblemIsReportedForAHostCheck(): void
    {
        $renditionRoutingProblem = $this->driveOfficeRenditionsService->findRenditionRoutingProblem();
        self::assertNotNull($renditionRoutingProblem);
        self::assertStringContainsString('officeRenditions.enabled', $renditionRoutingProblem);
        self::assertStringContainsString(DriveOfficeRenditionsService::FAILURE_NOT_ROUTED_TO_WORKER, $renditionRoutingProblem);

        $this->driveOfficeRenditionsService->testSettings->enabled = false;
        self::assertNull($this->driveOfficeRenditionsService->findRenditionRoutingProblem(), 'renditions off: nothing to report');
    }

    public function testAHostOverrideOfTheSwitchDecidesForTheRenditionAndTheSvgCopy(): void
    {
        $this->registerSanitizer();
        $this->driveBlobsService->isDerivedObjectWriteAllowedForTest = false;

        // the switch alone: neither a conversion nor a copy
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        self::assertSame([], $this->driveOfficeRenditionsService->dispatchedVersionIds);
        $svgNode = $this->storeFile('Logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>x()</script></svg>', self::SVG_CONTENT_TYPE);
        self::assertFalse($this->driveContentGrantsService->createContentGrant($svgNode, DriveContentGrant::PURPOSE_INLINE)->isInline);
        self::assertFalse($this->objectStorage->objectExists(DriveBlobsService::buildSanitizedSvgStorageKey(self::BLOB_STORAGE_KEY)));
    }

    protected function registerSanitizer(): void
    {
        $driveSvgSanitizersService = new DriveSvgSanitizersService();
        $driveSvgSanitizersService->setDriveSvgSanitizers([
            new class implements DriveSvgSanitizer {
                public function sanitizeSvg(string $svgBytes): ?string
                {
                    return DriveOfficeRenditionRoutingAndSwitchTest::SANITIZED_SVG;
                }
            },
        ]);
        $this->driveContentGrantsService->testDriveSvgSanitizersService = $driveSvgSanitizersService;
    }
}
