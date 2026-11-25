<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRendition;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRenditionSettings;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Exceptions\DriveOfficeRenditionFailedException;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionHandler;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionMessage;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\InMemoryDriveVersionsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ListItemTestDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RecordingDriveActivityEventsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\SmallCapsDriveOfficeRenditionsService;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The PDF rendition of Office files without a database and without LibreOffice: when it is offered, where it is
 * stored, the states a reader is answered (READY, PENDING, FAILED) with one dispatch per content, what the worker
 * refuses before the converter runs, the command line, environment and profile the converter gets, and the purpose
 * RENDITION in the content delivery.
 */
class DriveOfficeRenditionsTest extends DriveOfficeRenditionTestCase
{
    public function testTheSettingsTakeTheirDefaultsAndTheShippedBlockEqualsThem(): void
    {
        $defaultSettings = DriveOfficeRenditionSettings::fromArray([]);
        self::assertFalse($defaultSettings->enabled, 'off until a host switches it on');
        self::assertSame(50 * 1024 * 1024, $defaultSettings->maxSourceBytes);
        self::assertSame(120, $defaultSettings->timeoutSeconds);
        self::assertSame(300, $defaultSettings->maxPages);
        self::assertSame('soffice', $defaultSettings->binary);
        self::assertSame([], $defaultSettings->commandPrefix);
        self::assertSame(4 * 1024 * 1024 * 1024, $defaultSettings->addressSpaceBytes);

        $shippedSettings = require dirname(__DIR__, 3) . '/config/app/CloudDrive/settings.php';
        self::assertEquals($defaultSettings->toObject(), DriveOfficeRenditionSettings::fromArray($shippedSettings['officeRenditions'])->toObject());

        $hostSettings = DriveOfficeRenditionSettings::fromArray([
            'enabled' => true,
            'timeoutSeconds' => 1,
            'maxPages' => 0,
            'binary' => ' /opt/libreoffice/program/soffice ',
            'commandPrefix' => ['setpriv', '', '--reuid=65534', '--'],
            'addressSpaceBytes' => null,
        ]);
        self::assertTrue($hostSettings->enabled);
        self::assertSame(DriveOfficeRenditionSettings::MIN_TIMEOUT_SECONDS, $hostSettings->timeoutSeconds, 'never below the minimum');
        self::assertSame(1, $hostSettings->maxPages);
        self::assertSame('/opt/libreoffice/program/soffice', $hostSettings->binary);
        self::assertSame(['setpriv', '--reuid=65534', '--'], $hostSettings->commandPrefix);
        self::assertNull($hostSettings->addressSpaceBytes, 'null = no cap');
        self::assertSame([], DriveOfficeRenditionSettings::fromArray(['commandPrefix' => 'sudo -u nobody'])->commandPrefix, 'a command string is never split into words');
    }

    public function testARenditionIsOfferedForTheZipBasedOfficeFormatsWithinTheSize(): void
    {
        foreach (['Report.docx', 'Numbers.xlsx', 'Deck.pptx', 'Report.odt', 'Numbers.ods', 'Deck.odp', 'SHOUTING.DOCX'] as $fileName) {
            self::assertTrue($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode($fileName)), $fileName);
        }
        foreach (['Old.doc', 'Old.xls', 'Old.ppt', 'Paper.pdf', 'Notes.txt', 'Archive.zip', 'Text.rtf', 'Macros.docm', 'NoExtension'] as $fileName) {
            self::assertFalse($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode($fileName)), $fileName);
        }
        self::assertSame('writer_pdf_Export', DriveOfficeRenditionsService::findExportFilter('a.docx'));
        self::assertSame('calc_pdf_Export', DriveOfficeRenditionsService::findExportFilter('a.ods'));
        self::assertSame('impress_pdf_Export', DriveOfficeRenditionsService::findExportFilter('a.pptx'));
        self::assertNull(DriveOfficeRenditionsService::findExportFilter('a.doc'));

        $maxSourceBytes = $this->driveOfficeRenditionsService->testSettings->maxSourceBytes;
        self::assertTrue($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode('Report.docx', $maxSourceBytes)));
        self::assertFalse($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode('Report.docx', $maxSourceBytes + 1)));
        self::assertFalse($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode('Report.docx', 10), $maxSourceBytes + 1), 'the size of the content to convert decides');
        self::assertFalse($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode('Folder.docx', 0, null, DriveNode::NODE_TYPE_FOLDER)));

        foreach ([null, 'application/octet-stream', 'application/zip', self::DOCX_CONTENT_TYPE, 'application/vnd.oasis.opendocument.text'] as $contentType) {
            self::assertTrue($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode('Report.docx', 10, $contentType)), (string)$contentType);
        }
        foreach (['image/png', 'text/html', 'application/pdf', 'application/msword'] as $contentType) {
            self::assertFalse($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode('Report.docx', 10, $contentType)), $contentType);
        }

        $this->driveOfficeRenditionsService->testSettings->enabled = false;
        self::assertFalse($this->driveOfficeRenditionsService->isRenditionOffered($this->buildFileNode('Report.docx')), 'switched off');
    }

    public function testTheDerivedObjectsLiveNextToTheBlobAndGoWithIt(): void
    {
        self::assertSame('1/2026/700-test.rendition.pdf', DriveOfficeRenditionsService::buildRenditionStorageKey(self::BLOB_STORAGE_KEY));
        self::assertSame('1/2026/700-test.rendition.failed', DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY));
        self::assertSame('1/2026/700-test.rendition.pending', DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY));
        $derivedStorageKeys = DriveOfficeRenditionsService::buildDerivedStorageKeys(self::BLOB_STORAGE_KEY);
        self::assertCount(3, $derivedStorageKeys);

        $this->storeFile('Report.docx', $this->buildDocx());
        foreach ($derivedStorageKeys as $derivedStorageKey) {
            $this->objectStorage->putObject($derivedStorageKey, 'derived');
        }
        $this->driveBlobsService->deleteDerivedObjects($this->driveBlobsService->blobsById[700]);
        foreach ($derivedStorageKeys as $derivedStorageKey) {
            self::assertFalse($this->objectStorage->objectExists($derivedStorageKey), $derivedStorageKey);
        }
        self::assertTrue($this->objectStorage->objectExists(self::BLOB_STORAGE_KEY), 'the blob itself is the purge\'s');
    }

    public function testTheFirstRequestDispatchesOnceAndTheNextOnesWait(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());

        $firstAnswer = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $firstAnswer->state);
        self::assertNull($firstAnswer->grant);
        self::assertNull($firstAnswer->reason);
        self::assertSame([70], $this->driveOfficeRenditionsService->dispatchedVersionIds);
        self::assertSame(
            ['requestedAt' => $this->driveOfficeRenditionsService->testTimestamp],
            $this->readStoredMarker(DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY))
        );

        $this->driveOfficeRenditionsService->testTimestamp += 3;
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        $this->driveOfficeRenditionsService->testTimestamp += 2 * 120 + DriveOfficeRenditionsService::LOST_PENDING_GRACE_SECONDS - 3;
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state, 'twice the timeout plus the grace is still a wait');
        self::assertSame([70], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'one dispatch per content');
    }

    public function testAPendingMarkerOlderThanTwiceTheTimeoutIsLostAndDispatchedAgain(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->requestRendition($driveNode);
        $firstRequestedAt = $this->driveOfficeRenditionsService->testTimestamp;

        $this->driveOfficeRenditionsService->testTimestamp += 2 * 120 + DriveOfficeRenditionsService::LOST_PENDING_GRACE_SECONDS + 1;
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        self::assertSame([70, 70], $this->driveOfficeRenditionsService->dispatchedVersionIds);
        $pendingMarker = $this->readStoredMarker(DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY));
        self::assertGreaterThan($firstRequestedAt, $pendingMarker['requestedAt'], 'the marker is the new dispatch\'s');

        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        self::assertCount(2, $this->driveOfficeRenditionsService->dispatchedVersionIds);
    }

    public function testADispatchThatFailsLeavesNoPendingMarker(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->isDispatchFailing = true;
        try {
            $this->driveOfficeRenditionsService->requestRendition($driveNode);
            self::fail('the failing dispatch is the caller\'s error');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('the broker is away', $runtimeException->getMessage());
        }
        self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY)));

        $this->driveOfficeRenditionsService->isDispatchFailing = false;
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        self::assertSame([70], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'the next request dispatches');
    }

    public function testAStoredRenditionIsReadyWithAGrantThatServesThePdfInline(): void
    {
        $driveNode = $this->storeFile('Quarterly Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->requestRendition($driveNode);
        $renderAnswer = $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);
        self::assertSame(DriveOfficeRendition::STATE_READY, $renderAnswer->state);
        self::assertNull($renderAnswer->grant, 'the worker has no reader');

        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_READY, $driveOfficeRendition->state);
        self::assertNull($driveOfficeRendition->reason);
        $driveContentGrant = $driveOfficeRendition->grant;
        self::assertInstanceOf(DriveContentGrant::class, $driveContentGrant);
        self::assertSame(DriveContentGrant::PURPOSE_RENDITION, $driveContentGrant->purpose);
        self::assertSame('Quarterly Report.pdf', $driveContentGrant->fileName);
        self::assertSame('application/pdf', $driveContentGrant->contentType);
        self::assertTrue($driveContentGrant->isInline);
        self::assertSame(strlen((string)$this->driveOfficeRenditionsService->convertedBytes), $driveContentGrant->sizeBytes);
        self::assertNull($driveContentGrant->sha256, 'the source\'s hash is not the PDF\'s');
        self::assertSame(7, $driveContentGrant->driveNodeId);
        self::assertSame(70, $driveContentGrant->driveFileVersionId);
        self::assertStringStartsWith('/api/public/drive/content/', $driveContentGrant->url, 'a token URL of the backend');
        self::assertStringNotContainsString('rendition.pdf', $driveContentGrant->url, 'the storage key stays on the server');
        self::assertSame([70], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'READY dispatches nothing');

        $token = substr($driveContentGrant->url, strrpos($driveContentGrant->url, '/') + 1);
        $response = $this->driveContentStreamsService->createContentStreamResponse($token, Request::create('/', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string)$response->headers->get('Content-Disposition'));
        self::assertStringContainsString("filename*=utf-8''Quarterly%20Report.pdf", (string)$response->headers->get('Content-Disposition'));
        self::assertNotNull($response->headers->get('ETag'));
        ob_start();
        $response->sendContent();
        self::assertSame($this->driveOfficeRenditionsService->convertedBytes, ob_get_clean());
        self::assertSame([DriveContentGrant::PURPOSE_RENDITION], $this->driveContentStreamsService->recordedAccessPurposes);

        $rangeResponse = $this->driveContentStreamsService->createContentStreamResponse($token, Request::create('/', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=0-4']));
        self::assertSame(206, $rangeResponse->getStatusCode(), 'a PDF viewer reads ranges');
    }

    public function testAFetchedRenditionIsRecordedAsViewed(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $driveContentGrant = new DriveContentGrant();
        $driveContentGrant->purpose = DriveContentGrant::PURPOSE_RENDITION;
        $driveContentGrant->driveNodeId = 7;
        $driveContentGrant->driveFileVersionId = 70;
        $recordingDriveActivityEventsService = new RecordingDriveActivityEventsService();
        $driveActivityEvent = $recordingDriveActivityEventsService->recordContentAccess($driveNode, $driveContentGrant, 'INTERNAL');
        self::assertSame(DriveChangeEvent::EVENT_VIEWED, $driveActivityEvent?->eventType);
        self::assertSame(DriveContentGrant::PURPOSE_RENDITION, $driveActivityEvent?->contentVariant);
        self::assertSame(70, $driveActivityEvent?->driveFileVersionId);
    }

    public function testTheContentRouteServesAStoredRenditionAndNeverStartsOne(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        self::assertContains(DriveContentGrant::PURPOSE_RENDITION, DriveContentGrant::PEOPLE_PURPOSES);
        self::assertContains(DriveContentGrant::PURPOSE_RENDITION, DriveContentGrant::PURPOSES);
        try {
            $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_RENDITION);
            self::fail('no rendition yet');
        } catch (NotFoundException $notFoundException) {
            self::assertSame('This file has no PDF rendition (yet).', $notFoundException->getMessage());
        }
        self::assertSame([], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'only the rendition route dispatches');
        self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY)));

        $this->objectStorage->putObject(DriveOfficeRenditionsService::buildRenditionStorageKey(self::BLOB_STORAGE_KEY), '%PDF-1.7 stored');
        $driveContentDelivery = $this->driveContentGrantsService->resolveContentDelivery($driveNode, DriveContentGrant::PURPOSE_RENDITION);
        self::assertSame(DriveOfficeRenditionsService::buildRenditionStorageKey(self::BLOB_STORAGE_KEY), $driveContentDelivery->storageKey);
        self::assertSame('Report.pdf', $driveContentDelivery->fileName);
        self::assertSame('application/pdf', $driveContentDelivery->contentType);
        self::assertSame('application/pdf', $driveContentDelivery->inlineContentType);
        self::assertTrue($driveContentDelivery->isInline());
        self::assertSame(15, $driveContentDelivery->sizeBytes);
        self::assertNotNull($driveContentDelivery->etag);
        self::assertSame(DriveContentGrant::PURPOSE_RENDITION, $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_RENDITION)->purpose);

        // the download of the same file still is the original
        $downloadDelivery = $this->driveContentGrantsService->resolveContentDelivery($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
        self::assertSame(self::BLOB_STORAGE_KEY, $downloadDelivery->storageKey);
        self::assertSame('Report.docx', $downloadDelivery->fileName);
        self::assertNull($downloadDelivery->etag);

        // a rendition is content of the file: revoked rights and a blob blocked since refuse it like the original
        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_BLOCKED;
        try {
            $this->driveContentGrantsService->resolveContentDelivery($driveNode, DriveContentGrant::PURPOSE_RENDITION);
            self::fail('a blocked blob has no readable rendition');
        } catch (ForbiddenException) {
        }
        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_RELEASED;
        $this->driveSpacesService->isReadRevoked = true;
        $this->expectException(ForbiddenException::class);
        $this->driveContentGrantsService->resolveContentDelivery($driveNode, DriveContentGrant::PURPOSE_RENDITION);
    }

    public function testTheRequestRunsTheChecksOfEveryContentRead(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());

        $this->driveSpacesService->isReadRevoked = true;
        try {
            $this->driveOfficeRenditionsService->requestRendition($driveNode);
            self::fail('no read right');
        } catch (ForbiddenException) {
        }
        $this->driveSpacesService->isReadRevoked = false;

        $driveNode->trashedAt = DateTime::fromTimestamp(time());
        try {
            $this->driveOfficeRenditionsService->requestRendition($driveNode);
            self::fail('a trashed file has no rendition');
        } catch (ForbiddenException $forbiddenException) {
            self::assertStringContainsString('trash', $forbiddenException->getMessage());
        }
        $driveNode->trashedAt = null;

        foreach ([DriveBlob::STATE_PENDING, DriveBlob::STATE_BLOCKED, DriveBlob::STATE_SCAN_FAILED] as $blobState) {
            $this->driveBlobsService->blobsById[700]->state = $blobState;
            try {
                $this->driveOfficeRenditionsService->requestRendition($driveNode);
                self::fail('unreleased content has no rendition: ' . $blobState);
            } catch (ForbiddenException) {
            }
        }
        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_RELEASED;

        $otherVersion = (new ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $otherVersion->id = 71;
        $otherVersion->driveNodeId = 8;
        $otherVersion->driveBlobId = 700;
        try {
            $this->driveOfficeRenditionsService->requestRendition($driveNode, $otherVersion);
            self::fail('a version of another file');
        } catch (NotFoundException) {
        }

        $this->driveOfficeRenditionsService->testSettings->enabled = false;
        try {
            $this->driveOfficeRenditionsService->requestRendition($driveNode);
            self::fail('switched off');
        } catch (BadRequestException $badRequestException) {
            self::assertSame('This file has no PDF rendition.', $badRequestException->getMessage());
        }
        $this->driveOfficeRenditionsService->testSettings->enabled = true;

        $pictureNode = $this->storeFile('Picture.png', 'png bytes', 'image/png');
        try {
            $this->driveOfficeRenditionsService->requestRendition($pictureNode);
            self::fail('no Office file');
        } catch (BadRequestException) {
        }
        self::assertSame([], $this->driveOfficeRenditionsService->dispatchedVersionIds);
        self::assertSame([], $this->objectStorage->listObjects('1/2026/700-test.rendition')->getElements(), 'no refused request leaves a marker');
    }

    public function testTheWorkerDownloadsConvertsStoresAndCleansUp(): void
    {
        $docxBytes = $this->buildDocx();
        $driveNode = $this->storeFile('Report.docx', $docxBytes);
        $this->driveOfficeRenditionsService->requestRendition($driveNode);

        $renderAnswer = $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);

        self::assertSame(DriveOfficeRendition::STATE_READY, $renderAnswer->state);
        self::assertSame(1, $this->driveOfficeRenditionsService->conversionCount);
        self::assertSame($docxBytes, $this->driveOfficeRenditionsService->lastSourceBytes, 'the converter read a local copy');
        self::assertSame($this->driveOfficeRenditionsService->lastWorkingDirectory . '/in/source.docx', $this->driveOfficeRenditionsService->lastSourceFilePath, 'under a name PHP chose');
        self::assertTrue($this->driveOfficeRenditionsService->wasProfileWrittenBeforeConversion);
        self::assertSame($this->driveOfficeRenditionsService->convertedBytes, $this->objectStorage->getObjectContent(DriveOfficeRenditionsService::buildRenditionStorageKey(self::BLOB_STORAGE_KEY)));
        self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY)));
        self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY)));
        self::assertDirectoryDoesNotExist((string)$this->driveOfficeRenditionsService->lastWorkingDirectory);

        // another version of the same content: nothing is converted twice
        self::assertSame(DriveOfficeRendition::STATE_READY, $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70])->state);
        self::assertSame(1, $this->driveOfficeRenditionsService->conversionCount);
    }

    public function testAHostWithoutRoutingAnswersFromTheSameRequest(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->isRenderedOnDispatch = true;
        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_READY, $driveOfficeRendition->state);
        self::assertInstanceOf(DriveContentGrant::class, $driveOfficeRendition->grant);
    }

    public function testAHostThatStartsNoConversionAnswersOnlyAStoredPdfAndWritesNothing(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $pendingMarkerStorageKey = DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY);
        $failureMarkerStorageKey = DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY);
        self::assertTrue((new DriveOfficeRenditionsService())->isConversionStartAllowed(), 'the package starts conversions unless a host says no');
        $this->driveOfficeRenditionsService->isConversionStartAllowedForTest = false;

        // no PDF yet: FAILED with its own reason, no marker, no dispatch, no conversion
        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $driveOfficeRendition->state);
        self::assertSame(DriveOfficeRenditionsService::FAILURE_REASONS[DriveOfficeRenditionsService::FAILURE_NOT_STARTED_HERE], $driveOfficeRendition->reason);
        self::assertNull($driveOfficeRendition->grant);
        self::assertSame([], $this->driveOfficeRenditionsService->dispatchedVersionIds);
        self::assertSame(0, $this->driveOfficeRenditionsService->conversionCount);
        self::assertFalse($this->objectStorage->objectExists($pendingMarkerStorageKey));
        self::assertFalse($this->objectStorage->objectExists($failureMarkerStorageKey));

        // markers another environment left are neither read as an answer nor removed, however old
        $staleMarker = json_encode(['requestedAt' => 1], JSON_THROW_ON_ERROR);
        $this->objectStorage->putObject($pendingMarkerStorageKey, $staleMarker, 'application/json');
        $this->objectStorage->putObject($failureMarkerStorageKey, json_encode(['reasonCode' => DriveOfficeRenditionsService::FAILURE_TIMEOUT, 'failedAt' => 1], JSON_THROW_ON_ERROR), 'application/json');
        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $driveOfficeRendition->state);
        self::assertSame(DriveOfficeRenditionsService::FAILURE_REASONS[DriveOfficeRenditionsService::FAILURE_NOT_STARTED_HERE], $driveOfficeRendition->reason);
        self::assertTrue($this->objectStorage->objectExists($pendingMarkerStorageKey), 'a lost marker stays: removing it is a write');
        self::assertTrue($this->objectStorage->objectExists($failureMarkerStorageKey), 'an expired failure stays');
        self::assertSame([], $this->driveOfficeRenditionsService->dispatchedVersionIds);

        // a PDF the owning environment made is served
        $this->objectStorage->deleteObject($pendingMarkerStorageKey);
        $this->objectStorage->deleteObject($failureMarkerStorageKey);
        $this->driveOfficeRenditionsService->isConversionStartAllowedForTest = true;
        $this->driveOfficeRenditionsService->requestRendition($driveNode);
        $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);
        $this->driveOfficeRenditionsService->isConversionStartAllowedForTest = false;
        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_READY, $driveOfficeRendition->state);
        self::assertInstanceOf(DriveContentGrant::class, $driveOfficeRendition->grant);
        self::assertSame(DriveContentGrant::PURPOSE_RENDITION, $driveOfficeRendition->grant->purpose);
        self::assertSame([70], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'only the allowed request dispatched');
    }

    public function testAFailedConversionAnswersItsReasonAndIsTriedAgainLater(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->requestRendition($driveNode);
        $this->driveOfficeRenditionsService->conversionFailure = DriveOfficeRenditionFailedException::forReason(
            DriveOfficeRenditionsService::FAILURE_CONVERSION,
            'the converter ended with exit code 77: Error: source file could not be loaded /tmp/secret-path'
        );

        $renderAnswer = $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $renderAnswer->state);
        self::assertDirectoryDoesNotExist((string)$this->driveOfficeRenditionsService->lastWorkingDirectory, 'removed after a failure too');
        self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY)));
        self::assertSame(
            ['reasonCode' => DriveOfficeRenditionsService::FAILURE_CONVERSION, 'failedAt' => $this->driveOfficeRenditionsService->testTimestamp],
            $this->readStoredMarker(DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY)),
            'the marker keeps a code, never the converter\'s output'
        );

        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $driveOfficeRendition->state);
        self::assertSame('The file could not be converted to a PDF preview.', $driveOfficeRendition->reason);
        self::assertStringNotContainsString('secret-path', (string)json_encode($driveOfficeRendition->toObject()));
        self::assertNull($driveOfficeRendition->grant);
        self::assertSame([70], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'a kept failure dispatches nothing');

        $this->driveOfficeRenditionsService->testTimestamp += DriveOfficeRenditionsService::FAILURE_RETRY_SECONDS + 1;
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        self::assertSame([70, 70], $this->driveOfficeRenditionsService->dispatchedVersionIds, 'after the retry time it is converted again');
        self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY)));

        // the retry succeeds
        $this->driveOfficeRenditionsService->conversionFailure = null;
        $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);
        self::assertSame(DriveOfficeRendition::STATE_READY, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
    }

    public function testFailuresThatSayNothingAboutTheFileAreRetriedSoon(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $this->driveOfficeRenditionsService->requestRendition($driveNode);
        $this->driveOfficeRenditionsService->conversionFailure = DriveOfficeRenditionFailedException::forReason(DriveOfficeRenditionsService::FAILURE_UNAVAILABLE, 'the converter cannot be started: soffice is not installed');
        $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);

        $driveOfficeRendition = $this->driveOfficeRenditionsService->requestRendition($driveNode);
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $driveOfficeRendition->state);
        self::assertSame(DriveOfficeRenditionsService::FAILURE_REASONS[DriveOfficeRenditionsService::FAILURE_UNAVAILABLE], $driveOfficeRendition->reason);

        $this->driveOfficeRenditionsService->testTimestamp += DriveOfficeRenditionsService::TRANSIENT_FAILURE_RETRY_SECONDS + 1;
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        self::assertSame([70, 70], $this->driveOfficeRenditionsService->dispatchedVersionIds);
    }

    public function testTheWorkerTakesOnlyAPdfWithinTheCap(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        foreach (['<html>not a pdf</html>' => 'no PDF by its first bytes', '' => 'an empty file'] as $convertedBytes => $case) {
            $this->driveOfficeRenditionsService->convertedBytes = (string)$convertedBytes;
            self::assertSame(DriveOfficeRendition::STATE_FAILED, $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70])->state, $case);
            self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildRenditionStorageKey(self::BLOB_STORAGE_KEY)), $case);
            self::assertSame(DriveOfficeRenditionsService::FAILURE_CONVERSION, $this->readStoredMarker(DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY))['reasonCode'], $case);
        }
        $this->driveOfficeRenditionsService->convertedBytes = null;
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70])->state, 'the converter wrote nothing');
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);

        $linkedOutputPath = $this->rootDirectory . '/scratch/linked.pdf';
        symlink($this->writeScratchFile('%PDF-1.7 elsewhere', 'pdf'), $linkedOutputPath);
        try {
            $this->driveOfficeRenditionsService->requirePdfOutput($linkedOutputPath);
            self::fail('a link is no output');
        } catch (DriveOfficeRenditionFailedException $driveOfficeRenditionFailedException) {
            self::assertSame(DriveOfficeRenditionsService::FAILURE_CONVERSION, $driveOfficeRenditionFailedException->reasonCode);
        }
        $this->driveOfficeRenditionsService->requirePdfOutput($this->writeScratchFile('%PDF-1.7 fine', 'pdf'));
    }

    public function testTheWorkerNeverOpensARefusedContainer(): void
    {
        $refusedContents = [
            'no ZIP at all' => 'just some text that claims to be a document',
            'a ZIP that is no Office container' => $this->buildContainer(['readme.txt' => 'hello']),
            'an entry name that leaves its root' => $this->buildContainer(['[Content_Types].xml' => '<Types/>', '../../etc/cron.d/job' => 'x']),
            'compressed beyond the ratio' => $this->buildContainer(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => str_repeat('A', 4 * 1024 * 1024)]),
        ];
        foreach ($refusedContents as $case => $content) {
            $this->storeFile('Report.docx', $content);
            $this->objectStorage->deleteObject(DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY));
            $renderAnswer = $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);
            self::assertSame(DriveOfficeRendition::STATE_FAILED, $renderAnswer->state, $case);
            self::assertSame(DriveOfficeRenditionsService::FAILURE_REASONS[DriveOfficeRenditionsService::FAILURE_NOT_A_DOCUMENT], $renderAnswer->reason, $case);
            self::assertSame(
                DriveOfficeRenditionsService::FAILURE_NOT_A_DOCUMENT,
                $this->readStoredMarker(DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY))['reasonCode'],
                $case
            );
        }
        self::assertSame(0, $this->driveOfficeRenditionsService->conversionCount, 'the converter never started');
        self::assertSame([], glob(sys_get_temp_dir() . '/ddd-office-render-*/in/source.docx') ?: [], 'no working directory is left');
    }

    public function testTheContainerCapsRefuseBombs(): void
    {
        $smallCapsService = new SmallCapsDriveOfficeRenditionsService();
        $marker = ['[Content_Types].xml' => '<Types/>'];
        $refusedContainers = [
            'more entries than the cap' => $this->buildContainer($marker + ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']),
            'expands beyond the cap' => $this->buildContainer($marker + ['word/document.xml' => random_bytes(5000)]),
            'compressed beyond the ratio' => $this->buildContainer($marker + ['word/document.xml' => str_repeat('A', 4000)]),
        ];
        foreach ($refusedContainers as $case => $containerBytes) {
            try {
                $smallCapsService->inspectSourceArchive($this->writeScratchFile($containerBytes, 'docx'), 'docx');
                self::fail($case);
            } catch (DriveOfficeRenditionFailedException $driveOfficeRenditionFailedException) {
                self::assertSame(DriveOfficeRenditionsService::FAILURE_NOT_A_DOCUMENT, $driveOfficeRenditionFailedException->reasonCode, $case);
            }
        }
        $archiveInspection = $smallCapsService->inspectSourceArchive($this->writeScratchFile($this->buildContainer($marker + ['word/document.xml' => random_bytes(200)]), 'docx'), 'docx');
        self::assertSame(2, $archiveInspection->entryCount);

        // the marker entry is the format's: an OOXML container is no ODF document
        $openDocument = $this->buildContainer(['META-INF/manifest.xml' => '<manifest/>', 'content.xml' => '<office/>']);
        self::assertSame(2, $this->driveOfficeRenditionsService->inspectSourceArchive($this->writeScratchFile($openDocument, 'odt'), 'odt')->entryCount);
        $this->expectException(DriveOfficeRenditionFailedException::class);
        $this->driveOfficeRenditionsService->inspectSourceArchive($this->writeScratchFile($openDocument, 'docx'), 'docx');
    }

    public function testTheWorkerChecksSizeTypeAndSwitchAgain(): void
    {
        $driveNode = $this->storeFile('Report.docx', $this->buildDocx());
        $driveFileVersion = $this->driveVersionsService->versionsById[70];
        $pendingMarkerStorageKey = DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY);
        $failureMarkerStorageKey = DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY);

        // switched off since the dispatch, or a blob blocked since: nothing is converted and no failure is kept
        $this->driveOfficeRenditionsService->requestRendition($driveNode);
        $this->driveOfficeRenditionsService->testSettings->enabled = false;
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $this->driveOfficeRenditionsService->renderRendition($driveFileVersion)->state);
        $this->driveOfficeRenditionsService->testSettings->enabled = true;
        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_BLOCKED;
        $this->objectStorage->putObject($pendingMarkerStorageKey, '{"requestedAt":1}');
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $this->driveOfficeRenditionsService->renderRendition($driveFileVersion)->state);
        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_RELEASED;
        self::assertFalse($this->objectStorage->objectExists($pendingMarkerStorageKey));
        self::assertFalse($this->objectStorage->objectExists($failureMarkerStorageKey));
        self::assertSame(0, $this->driveOfficeRenditionsService->conversionCount);

        // larger than the limit (the limit was lowered since the dispatch)
        $this->driveOfficeRenditionsService->testSettings->maxSourceBytes = 10;
        self::assertSame(
            DriveOfficeRenditionsService::FAILURE_REASONS[DriveOfficeRenditionsService::FAILURE_TOO_LARGE],
            $this->driveOfficeRenditionsService->renderRendition($driveFileVersion)->reason
        );
        self::assertSame(DriveOfficeRenditionsService::FAILURE_TOO_LARGE, $this->readStoredMarker($failureMarkerStorageKey)['reasonCode']);
        $this->driveOfficeRenditionsService->testSettings->maxSourceBytes = DriveOfficeRenditionSettings::DEFAULT_MAX_SOURCE_BYTES;

        // renamed to a type without a rendition since the dispatch
        $driveNode->name = 'Report.doc';
        self::assertSame(
            DriveOfficeRenditionsService::FAILURE_REASONS[DriveOfficeRenditionsService::FAILURE_NOT_A_DOCUMENT],
            $this->driveOfficeRenditionsService->renderRendition($driveFileVersion)->reason
        );
        self::assertSame(0, $this->driveOfficeRenditionsService->conversionCount);

        // the file or its content is gone
        unset($this->driveNodesService->nodesById[7]);
        self::assertSame(DriveOfficeRendition::STATE_FAILED, $this->driveOfficeRenditionsService->renderRendition($driveFileVersion)->state);
    }

    public function testTheConverterGetsLocalPathsItsOwnProfileThePageRangeAndThePrefix(): void
    {
        $workingDirectory = $this->rootDirectory . '/scratch/job';
        mkdir($workingDirectory . '/in', 0700, true);
        $sourceFilePath = $workingDirectory . '/in/source.xlsx';
        $driveOfficeRenditionSettings = new DriveOfficeRenditionSettings();
        $driveOfficeRenditionSettings->binary = PHP_BINARY;
        $driveOfficeRenditionSettings->timeoutSeconds = 45;
        $driveOfficeRenditionSettings->maxPages = 25;

        $expectedArguments = [
            '-env:UserInstallation=file://' . $workingDirectory . '/profile',
            '--headless',
            '--norestore',
            '--nolockcheck',
            '--nodefault',
            '--nofirststartwizard',
            '--convert-to',
            'pdf:calc_pdf_Export:{"PageRange":{"type":"string","value":"1-25"}}',
            '--outdir',
            $workingDirectory . '/out',
            $sourceFilePath,
        ];
        self::assertSame($expectedArguments, DriveOfficeRenditionsService::buildConvertArguments($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, 'xlsx'));
        foreach (DriveOfficeRenditionsService::buildConvertArguments($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, 'xlsx') as $argument) {
            self::assertDoesNotMatchRegularExpression('#https?://#', $argument, 'the converter never sees a URL');
        }

        $process = $this->driveOfficeRenditionsService->createConvertProcess($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, 'xlsx');
        $commandLine = $process->getCommandLine();
        $expectedTail = implode(' ', array_map($this->escapeArgument(...), [PHP_BINARY, ...$expectedArguments]));
        self::assertStringEndsWith($expectedTail, $commandLine, 'binary and arguments as separate words, nothing after the source path');
        $executableFinder = new ExecutableFinder();
        $timeoutBinary = $executableFinder->find('timeout');
        $prlimitBinary = $executableFinder->find('prlimit');
        $expectedHead = [];
        if ($timeoutBinary !== null) {
            array_push($expectedHead, $timeoutBinary, '-k', '5', '45');
        }
        if ($prlimitBinary !== null) {
            array_push($expectedHead, $prlimitBinary, '--as=' . DriveOfficeRenditionSettings::DEFAULT_ADDRESS_SPACE_BYTES, '--');
        }
        self::assertSame(trim(implode(' ', array_map($this->escapeArgument(...), $expectedHead)) . ' ' . $expectedTail), $commandLine, 'the kill switch and the cap lead, when installed');
        self::assertSame($workingDirectory, $process->getWorkingDirectory());
        self::assertSame(55.0, $process->getTimeout(), 'the process timeout backs the kill switch up');

        // a prefix: its first word is the process, the resolved converter follows the prefix
        $driveOfficeRenditionSettings->commandPrefix = ['/usr/bin/env', 'WRAPPED=1'];
        $driveOfficeRenditionSettings->addressSpaceBytes = null;
        $prefixedCommandLine = $this->driveOfficeRenditionsService->createConvertProcess($driveOfficeRenditionSettings, $workingDirectory, $sourceFilePath, 'xlsx')->getCommandLine();
        self::assertStringEndsWith(
            implode(' ', array_map($this->escapeArgument(...), ['/usr/bin/env', 'WRAPPED=1', PHP_BINARY, ...$expectedArguments])),
            $prefixedCommandLine
        );
        self::assertStringNotContainsString('--as=', $prefixedCommandLine, 'null = no cap');

        // a working directory with a character a URL escapes
        self::assertSame(
            '-env:UserInstallation=file:///var/tmp/my%20job/profile',
            DriveOfficeRenditionsService::buildConvertArguments($driveOfficeRenditionSettings, '/var/tmp/my job', '/var/tmp/my job/in/source.odp', 'odp')[0]
        );
        self::assertSame(
            'pdf:impress_pdf_Export:{"PageRange":{"type":"string","value":"1-25"}}',
            DriveOfficeRenditionsService::buildConvertArguments($driveOfficeRenditionSettings, '/var/tmp/job', '/var/tmp/job/in/source.odp', 'odp')[7]
        );
        $this->expectException(DriveOfficeRenditionFailedException::class);
        DriveOfficeRenditionsService::buildConvertArguments($driveOfficeRenditionSettings, '/var/tmp/job', '/var/tmp/job/in/source.doc', 'doc');
    }

    public function testTheConverterInheritsNoEnvironmentVariable(): void
    {
        $workingDirectory = $this->rootDirectory . '/scratch/job';
        mkdir($workingDirectory . '/in', 0700, true);
        putenv('DRIVE_RENDER_CANARY=secret-from-getenv');
        $_ENV['DRIVE_RENDER_CANARY_ENV'] = 'secret-from-env';
        $_SERVER['DRIVE_RENDER_CANARY_SERVER'] = 'secret-from-server';
        try {
            $driveOfficeRenditionSettings = new DriveOfficeRenditionSettings();
            $driveOfficeRenditionSettings->binary = PHP_BINARY;
            $driveOfficeRenditionSettings->addressSpaceBytes = null;
            // the "wrapper" is PHP printing its own environment; everything after `--` is ignored by it
            $driveOfficeRenditionSettings->commandPrefix = [PHP_BINARY, '-n', '-r', 'echo json_encode(getenv());', '--'];
            $process = $this->driveOfficeRenditionsService->createConvertProcess($driveOfficeRenditionSettings, $workingDirectory, $workingDirectory . '/in/source.docx', 'docx');

            $processEnvironment = $process->getEnv();
            foreach (['DRIVE_RENDER_CANARY', 'DRIVE_RENDER_CANARY_ENV', 'DRIVE_RENDER_CANARY_SERVER'] as $canaryName) {
                self::assertFalse($processEnvironment[$canaryName], $canaryName . ' is unset for the child');
            }
            $setVariables = array_filter($processEnvironment, 'is_string');
            ksort($setVariables);
            self::assertSame(
                ['HOME' => $workingDirectory, 'LANG' => 'C.UTF-8', 'PATH' => IsolatedProcess::SAFE_PATH, 'TMPDIR' => $workingDirectory],
                $setVariables,
                'only PATH, HOME, TMPDIR and LANG are set, HOME and TMPDIR inside the job'
            );

            $process->mustRun();
            $childEnvironment = json_decode($process->getOutput(), true);
            self::assertIsArray($childEnvironment);
            self::assertSame($workingDirectory, $childEnvironment['HOME']);
            self::assertSame(IsolatedProcess::SAFE_PATH, $childEnvironment['PATH']);
            // what the operating system or a wrapper adds by itself
            $systemVariableNames = ['PWD', 'SHLVL', '_', '__CF_USER_TEXT_ENCODING', 'OLDPWD'];
            self::assertSame([], array_values(array_diff(array_keys($childEnvironment), ['HOME', 'LANG', 'PATH', 'TMPDIR', ...$systemVariableNames])), 'no variable of the PHP process reaches the child');
            self::assertStringNotContainsString('secret-from', $process->getOutput());
        } finally {
            putenv('DRIVE_RENDER_CANARY');
            unset($_ENV['DRIVE_RENDER_CANARY_ENV'], $_SERVER['DRIVE_RENDER_CANARY_SERVER']);
        }
    }

    public function testTheProfileSwitchesOffMacrosLinksRemoteContentJavaUpdatesAndRecovery(): void
    {
        $workingDirectory = $this->rootDirectory . '/scratch/job';
        mkdir($workingDirectory, 0700, true);
        $profileDirectory = $this->driveOfficeRenditionsService->writeProfile($workingDirectory);
        self::assertSame($workingDirectory . '/profile', $profileDirectory);

        $registryModifications = (string)file_get_contents($profileDirectory . '/user/registrymodifications.xcu');
        $registryDocument = new \DOMDocument();
        self::assertTrue($registryDocument->loadXML($registryModifications), 'well-formed');
        $valuesByProperty = [];
        foreach ($registryDocument->getElementsByTagName('item') as $itemElement) {
            $propertyElement = $itemElement->getElementsByTagName('prop')->item(0);
            self::assertSame('fuse', $propertyElement->getAttributeNS('http://openoffice.org/2001/registry', 'op'));
            $valuesByProperty[$itemElement->getAttributeNS('http://openoffice.org/2001/registry', 'path') . '/' . $propertyElement->getAttributeNS('http://openoffice.org/2001/registry', 'name')]
                = $propertyElement->getElementsByTagName('value')->item(0)->textContent;
        }
        $expectedValues = [
            '/org.openoffice.Office.Common/Security/Scripting/MacroSecurityLevel' => '3',
            '/org.openoffice.Office.Common/Security/Scripting/DisableMacrosExecution' => 'true',
            '/org.openoffice.Office.Common/Security/Scripting/DisableActiveContent' => 'true',
            '/org.openoffice.Office.Common/Security/Scripting/BlockUntrustedRefererLinks' => 'true',
            '/org.openoffice.Office.Writer/Content/Update/Link' => '0',
            '/org.openoffice.Office.Calc/Content/Update/Link' => '2',
            '/org.openoffice.Office.Calc/Formula/Load/OOXMLRecalcMode' => '1',
            '/org.openoffice.Office.Calc/Formula/Load/ODFRecalcMode' => '1',
            '/org.openoffice.Office.Java/VirtualMachine/NetAccess' => '3',
            "/org.openoffice.Office.Jobs/Jobs/org.openoffice.Office.Jobs:Job['UpdateCheck']/Arguments/AutoCheckEnabled" => 'false',
            '/org.openoffice.Office.Common/Misc/CrashReport' => 'false',
            '/org.openoffice.Office.Recovery/AutoSave/Enabled' => 'false',
            '/org.openoffice.Office.Recovery/RecoveryInfo/Enabled' => 'false',
        ];
        foreach ($expectedValues as $propertyPath => $expectedValue) {
            self::assertSame($expectedValue, $valuesByProperty[$propertyPath] ?? null, $propertyPath);
        }

        foreach (DriveOfficeRenditionsService::JAVA_SETTINGS_PLATFORMS as $javaSettingsPlatform) {
            $javaSettings = (string)file_get_contents($profileDirectory . '/user/config/javasettings_' . $javaSettingsPlatform . '.xml');
            self::assertStringContainsString('<enabled xsi:nil="false">false</enabled>', $javaSettings, $javaSettingsPlatform);
            self::assertTrue((new \DOMDocument())->loadXML($javaSettings));
        }
        self::assertContains('Linux_X86_64', DriveOfficeRenditionsService::JAVA_SETTINGS_PLATFORMS);
        self::assertContains('Linux_AARCH64', DriveOfficeRenditionsService::JAVA_SETTINGS_PLATFORMS);
    }

    public function testAFileCardSaysWhetherARenditionIsOffered(): void
    {
        $this->storeFile('Report.docx', $this->buildDocx());
        $driveFileCard = $this->driveContentGrantsService->findFileCards(['drive/7'])->first();
        self::assertTrue($driveFileCard->hasPdfRendition);
        self::assertNull($driveFileCard->thumbnailUrl);

        $this->driveOfficeRenditionsService->testSettings->enabled = false;
        self::assertFalse($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasPdfRendition, 'switched off');
        $this->driveOfficeRenditionsService->testSettings->enabled = true;

        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_PENDING;
        self::assertFalse($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasPdfRendition, 'not released: nothing to preview');

        $this->storeFile('Paper.pdf', '%PDF-1.7', 'application/pdf');
        self::assertFalse($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasPdfRendition, 'a PDF needs none');
    }

    public function testAListItemSaysWhetherARenditionIsOffered(): void
    {
        $inMemoryDriveVersionsService = new InMemoryDriveVersionsService();
        $listItemDriveNodesService = new ListItemTestDriveNodesService($this->driveSpacesService, $inMemoryDriveVersionsService, $this->driveOfficeRenditionsService);
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->id = 1;
        $driveNodes = new DriveNodes();
        $files = [
            11 => ['Report.docx', self::DOCX_CONTENT_TYPE, DriveBlob::STATE_RELEASED, 2000, false],
            12 => ['Waiting.docx', self::DOCX_CONTENT_TYPE, DriveBlob::STATE_PENDING, 2000, false],
            13 => ['Huge.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', DriveBlob::STATE_RELEASED, DriveOfficeRenditionSettings::DEFAULT_MAX_SOURCE_BYTES + 1, false],
            14 => ['Paper.pdf', 'application/pdf', DriveBlob::STATE_RELEASED, 2000, false],
            15 => ['Old.doc', 'application/msword', DriveBlob::STATE_RELEASED, 2000, false],
            16 => ['Trashed.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', DriveBlob::STATE_RELEASED, 2000, true],
        ];
        foreach ($files as $driveNodeId => [$name, $contentType, $blobState, $sizeBytes, $isTrashed]) {
            $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
            $driveBlob->id = $driveNodeId * 100;
            $driveBlob->state = $blobState;
            $driveBlob->sizeBytes = $sizeBytes;
            $driveBlob->contentType = $contentType;
            $listItemDriveNodesService->blobsById[$driveNodeId * 100] = $driveBlob;
            $inMemoryDriveVersionsService->addVersion($driveNodeId * 10, $driveNodeId, 1)->driveBlobId = $driveNodeId * 100;
            $driveNode = $this->buildFileNode($name, $sizeBytes, $contentType);
            $driveNode->id = $driveNodeId;
            $driveNode->latestVersionId = $driveNodeId * 10;
            $driveNode->currentVersionId = $blobState === DriveBlob::STATE_RELEASED ? $driveNodeId * 10 : null;
            $driveNode->trashedAt = $isTrashed ? DateTime::fromTimestamp(time()) : null;
            $driveNodes->add($driveNode);
        }
        $folderNode = $this->buildFileNode('Folder.docx', 0, null, DriveNode::NODE_TYPE_FOLDER);
        $folderNode->id = 17;
        $driveNodes->add($folderNode);

        $hasPdfRenditionByName = [];
        foreach ($listItemDriveNodesService->buildListItems($driveNodes, $driveSpace)->getElements() as $driveNodeListItem) {
            $hasPdfRenditionByName[$driveNodeListItem->name] = $driveNodeListItem->hasPdfRendition;
        }
        self::assertSame(
            ['Report.docx' => true, 'Waiting.docx' => false, 'Huge.xlsx' => false, 'Paper.pdf' => false, 'Old.doc' => false, 'Trashed.pptx' => false, 'Folder.docx' => false],
            $hasPdfRenditionByName
        );

        $this->driveOfficeRenditionsService->testSettings->enabled = false;
        self::assertFalse($listItemDriveNodesService->buildListItems($driveNodes, $driveSpace)->first()->hasPdfRendition, 'switched off');
    }

    public function testTheMessageNamesItsHandlerAndTransport(): void
    {
        self::assertSame('drive_render', DriveOfficeRenditionHandler::TRANSPORT);
        self::assertSame(DriveOfficeRenditionHandler::class, DriveOfficeRenditionMessage::$messageHandler);
    }
}
