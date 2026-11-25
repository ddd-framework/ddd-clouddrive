<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveSvgSanitizer;
use DDD\Domain\CloudDrive\Services\DriveSvgSanitizersService;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Symfony\Component\HttpFoundation\Request;

/**
 * An SVG as a picture: the INLINE grant of an SVG serves the host's sanitised copy as `image/svg+xml` for an `<img>`
 * (inline, `nosniff`, the sandbox CSP), never the uploaded bytes; a download stays the original as an opaque
 * attachment. Without a sanitiser, for a refused or too large SVG and where derived objects may not be written
 * ({@see DriveBlobsService::isDerivedObjectWriteAllowed()}) the SVG is an attachment only. List items and file cards
 * say with `hasSanitizedImage` whether the picture is offered.
 */
class DriveSanitizedSvgImageTest extends DriveOfficeRenditionTestCase
{
    public const string SVG_CONTENT_TYPE = 'image/svg+xml';

    public const string HOSTILE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><circle r="4"/></svg>';

    public const string SANITIZED_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>';

    /** @var string[] The bytes the sanitiser was asked to clean */
    public array $sanitizedInputs = [];

    public bool $isSvgRefused = false;

    protected function registerSanitizer(): void
    {
        $test = $this;
        $driveSvgSanitizersService = new DriveSvgSanitizersService();
        $driveSvgSanitizersService->setDriveSvgSanitizers([
            new class ($test) implements DriveSvgSanitizer {
                public function __construct(public DriveSanitizedSvgImageTest $test)
                {
                }

                public function sanitizeSvg(string $svgBytes): ?string
                {
                    $this->test->sanitizedInputs[] = $svgBytes;
                    return $this->test->isSvgRefused ? null : DriveSanitizedSvgImageTest::SANITIZED_SVG;
                }
            },
        ]);
        $this->driveContentGrantsService->testDriveSvgSanitizersService = $driveSvgSanitizersService;
    }

    public function testTheInlineGrantOfAnSvgServesTheSanitisedCopyAsAPictureAndTheDownloadTheOriginal(): void
    {
        $this->registerSanitizer();
        $driveNode = $this->storeFile('Logo.svg', self::HOSTILE_SVG, self::SVG_CONTENT_TYPE);
        $sanitizedStorageKey = DriveBlobsService::buildSanitizedSvgStorageKey(self::BLOB_STORAGE_KEY);

        $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
        self::assertTrue($driveContentGrant->isInline);
        self::assertSame(self::SVG_CONTENT_TYPE, $driveContentGrant->contentType);
        self::assertStringStartsWith('/api/public/drive/content/', $driveContentGrant->url, 'a token URL of the backend');
        self::assertStringNotContainsString('sanitized', $driveContentGrant->url, 'the storage key stays on the server');
        self::assertSame(self::SANITIZED_SVG, $this->objectStorage->getObjectContent($sanitizedStorageKey), 'kept next to the blob');
        self::assertSame(self::HOSTILE_SVG, $this->objectStorage->getObjectContent(self::BLOB_STORAGE_KEY), 'the upload is untouched');

        $token = substr($driveContentGrant->url, strrpos($driveContentGrant->url, '/') + 1);
        $response = $this->driveContentStreamsService->createContentStreamResponse($token, Request::create('/', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::SVG_CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('inline', (string)$response->headers->get('Content-Disposition'));
        self::assertSame(UntrustedContentHeaders::CONTENT_SECURITY_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'none'", (string)$response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('sandbox', (string)$response->headers->get('Content-Security-Policy'));
        ob_start();
        $response->sendContent();
        $servedBytes = (string)ob_get_clean();
        self::assertSame(self::SANITIZED_SVG, $servedBytes);
        self::assertStringNotContainsString('script', $servedBytes);
        self::assertStringNotContainsString('onload', $servedBytes);

        // the copy is made once per blob
        $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
        self::assertCount(1, $this->sanitizedInputs);

        // a download is the uploaded file, as an attachment of the opaque type: a browser never renders it
        $downloadGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
        self::assertFalse($downloadGrant->isInline);
        $downloadToken = substr($downloadGrant->url, strrpos($downloadGrant->url, '/') + 1);
        $downloadResponse = $this->driveContentStreamsService->createContentStreamResponse($downloadToken, Request::create('/', 'GET'));
        self::assertSame(UntrustedContentHeaders::OPAQUE_CONTENT_TYPE, $downloadResponse->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string)$downloadResponse->headers->get('Content-Disposition'));
        ob_start();
        $downloadResponse->sendContent();
        self::assertSame(self::HOSTILE_SVG, ob_get_clean());
    }

    public function testAnSvgWithoutASanitisedCopyIsAnAttachmentOnly(): void
    {
        $sanitizedStorageKey = DriveBlobsService::buildSanitizedSvgStorageKey(self::BLOB_STORAGE_KEY);

        // a host without a sanitiser
        $driveNode = $this->storeFile('Logo.svg', self::HOSTILE_SVG, self::SVG_CONTENT_TYPE);
        $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
        self::assertFalse($driveContentGrant->isInline);
        self::assertFalse($this->objectStorage->objectExists($sanitizedStorageKey));
        $token = substr($driveContentGrant->url, strrpos($driveContentGrant->url, '/') + 1);
        $response = $this->driveContentStreamsService->createContentStreamResponse($token, Request::create('/', 'GET'));
        self::assertSame(UntrustedContentHeaders::OPAQUE_CONTENT_TYPE, $response->headers->get('Content-Type'), 'the original is never served as an SVG');
        self::assertStringStartsWith('attachment', (string)$response->headers->get('Content-Disposition'));

        // an SVG the sanitiser refuses
        $this->registerSanitizer();
        $this->isSvgRefused = true;
        self::assertFalse($this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE)->isInline);
        self::assertFalse($this->objectStorage->objectExists($sanitizedStorageKey));
        $this->isSvgRefused = false;

        // an SVG above the size the sanitiser takes: it is not even read
        $this->sanitizedInputs = [];
        $this->driveBlobsService->blobsById[700]->sizeBytes = DriveSvgSanitizersService::MAX_SVG_BYTES + 1;
        self::assertFalse($this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE)->isInline);
        self::assertSame([], $this->sanitizedInputs);
        self::assertFalse($this->objectStorage->objectExists($sanitizedStorageKey));
    }

    public function testAHostThatWritesNoDerivedObjectServesOnlyACopyThatExists(): void
    {
        $this->registerSanitizer();
        $driveNode = $this->storeFile('Logo.svg', self::HOSTILE_SVG, self::SVG_CONTENT_TYPE);
        $sanitizedStorageKey = DriveBlobsService::buildSanitizedSvgStorageKey(self::BLOB_STORAGE_KEY);
        $this->driveBlobsService->isDerivedObjectWriteAllowedForTest = false;

        // no copy yet: none is made, the sanitiser is not asked, the grant is an attachment
        $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
        self::assertFalse($driveContentGrant->isInline);
        self::assertSame([], $this->sanitizedInputs);
        self::assertFalse($this->objectStorage->objectExists($sanitizedStorageKey));
        self::assertFalse($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasSanitizedImage);
        self::assertFalse($this->objectStorage->objectExists($sanitizedStorageKey), 'a file card writes none either');

        // a copy the owning environment made is served
        $this->objectStorage->putObject($sanitizedStorageKey, self::SANITIZED_SVG, self::SVG_CONTENT_TYPE);
        $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
        self::assertTrue($driveContentGrant->isInline);
        self::assertSame(self::SVG_CONTENT_TYPE, $driveContentGrant->contentType);
        self::assertSame([], $this->sanitizedInputs);
        self::assertTrue($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasSanitizedImage);
    }

    public function testListItemsAndFileCardsSayWhetherThePictureIsOffered(): void
    {
        $twoMegabytes = DriveSvgSanitizersService::MAX_SVG_BYTES;
        self::assertFalse($this->driveContentGrantsService->isSanitizedImageOffered(self::SVG_CONTENT_TYPE, 900), 'a host without a sanitiser');

        $this->registerSanitizer();
        self::assertTrue($this->driveContentGrantsService->isSanitizedImageOffered(self::SVG_CONTENT_TYPE, 900));
        self::assertTrue($this->driveContentGrantsService->isSanitizedImageOffered('Image/SVG+XML; charset=utf-8', $twoMegabytes));
        self::assertFalse($this->driveContentGrantsService->isSanitizedImageOffered(self::SVG_CONTENT_TYPE, $twoMegabytes + 1), 'too large to sanitise');
        self::assertFalse($this->driveContentGrantsService->isSanitizedImageOffered(self::SVG_CONTENT_TYPE, null));
        self::assertFalse($this->driveContentGrantsService->isSanitizedImageOffered('image/png', 900), 'a raster picture is its own preview');
        self::assertFalse($this->driveContentGrantsService->isSanitizedImageOffered('text/html', 900));
        self::assertFalse($this->driveContentGrantsService->isSanitizedImageOffered(null, 900));

        // a card knows more than a list item: its inline URL is the copy, or the card offers no picture
        $this->storeFile('Logo.svg', self::HOSTILE_SVG, self::SVG_CONTENT_TYPE);
        $driveFileCard = $this->driveContentGrantsService->findFileCards(['drive/7'])->first();
        self::assertTrue($driveFileCard->hasSanitizedImage);
        self::assertNull($driveFileCard->thumbnailUrl, 'thumbnails stay raster pictures');
        self::assertFalse($driveFileCard->hasPdfRendition);

        $this->isSvgRefused = true;
        $this->objectStorage->deleteObject(DriveBlobsService::buildSanitizedSvgStorageKey(self::BLOB_STORAGE_KEY));
        self::assertFalse($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasSanitizedImage, 'the sanitiser refused it');
        $this->isSvgRefused = false;

        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_PENDING;
        self::assertFalse($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasSanitizedImage, 'not released: nothing to preview');

        $this->storeFile('Photo.png', 'png', 'image/png');
        self::assertFalse($this->driveContentGrantsService->findFileCards(['drive/7'])->first()->hasSanitizedImage);
    }
}
