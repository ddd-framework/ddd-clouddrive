<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RecordingUrlDriveBlobsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RecordingUrlObjectStorage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What a reader may open in the browser: raster images and PDF in their own type, plain texts as text/plain; HTML,
 * SVG, XML, JavaScript, Office files and archives only ever as attachments.
 */
class DriveInlineContentTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function contentTypes(): array
    {
        return [
            'jpeg' => ['image/jpeg', 'image/jpeg'],
            'webp' => ['image/webp', 'image/webp'],
            'pdf' => ['application/pdf', 'application/pdf'],
            'markdown' => ['text/markdown', DriveContentGrantsService::TEXT_INLINE_CONTENT_TYPE],
            'markdown with charset' => ['text/markdown; charset=utf-8', DriveContentGrantsService::TEXT_INLINE_CONTENT_TYPE],
            'plain text' => ['text/plain', DriveContentGrantsService::TEXT_INLINE_CONTENT_TYPE],
            'csv' => ['text/csv', DriveContentGrantsService::TEXT_INLINE_CONTENT_TYPE],
            'json' => ['application/json', DriveContentGrantsService::TEXT_INLINE_CONTENT_TYPE],
            'html' => ['text/html', null],
            'html with charset' => ['text/html; charset=utf-8', null],
            'xhtml' => ['application/xhtml+xml', null],
            'svg' => ['image/svg+xml', null],
            'xml' => ['application/xml', null],
            'text xml' => ['text/xml', null],
            'javascript' => ['text/javascript', null],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', null],
            'zip' => ['application/zip', null],
            'unknown' => [null, null],
        ];
    }

    public function testTheInlineTypeOfEachContentType(): void
    {
        $driveContentGrantsService = new DriveContentGrantsService();
        foreach (self::contentTypes() as $label => [$contentType, $expectedInlineContentType]) {
            self::assertSame($expectedInlineContentType, $driveContentGrantsService->resolveInlineContentType($contentType), $label);
        }
    }

    public function testAnInlinePdfIsServedAsPdfInlineAndATextAsPlainText(): void
    {
        $recordingUrlObjectStorage = new RecordingUrlObjectStorage(sys_get_temp_dir());
        $driveBlobsService = new RecordingUrlDriveBlobsService($recordingUrlObjectStorage);

        $driveBlobsService->createDownloadRequestUrl($this->buildBlob('application/pdf'), 'Deck.pdf', false, 60, 'application/pdf');
        self::assertSame('application/pdf', $recordingUrlObjectStorage->lastResponseContentType);
        self::assertStringStartsWith('inline', (string)$recordingUrlObjectStorage->lastResponseContentDisposition);

        $driveBlobsService->createDownloadRequestUrl($this->buildBlob('text/markdown'), 'Notes.md', false, 60, DriveContentGrantsService::TEXT_INLINE_CONTENT_TYPE);
        self::assertSame('text/plain; charset=utf-8', $recordingUrlObjectStorage->lastResponseContentType);
        self::assertStringStartsWith('inline', (string)$recordingUrlObjectStorage->lastResponseContentDisposition);
    }

    public function testHtmlAndSvgStayOpaqueAttachments(): void
    {
        $recordingUrlObjectStorage = new RecordingUrlObjectStorage(sys_get_temp_dir());
        $driveBlobsService = new RecordingUrlDriveBlobsService($recordingUrlObjectStorage);
        foreach (['text/html', 'image/svg+xml', 'text/javascript'] as $activeContentType) {
            // no inline type: the grant service resolves none for these, the blob service falls back to its own rule
            $driveBlobsService->createDownloadRequestUrl($this->buildBlob($activeContentType), 'page', false, 60, null);
            self::assertSame('application/octet-stream', $recordingUrlObjectStorage->lastResponseContentType, $activeContentType);
            self::assertStringStartsWith('attachment', (string)$recordingUrlObjectStorage->lastResponseContentDisposition, $activeContentType);
        }
        $driveBlobsService->createDownloadRequestUrl($this->buildBlob('application/pdf'), 'Deck.pdf', true, 60, 'application/pdf');
        self::assertStringStartsWith('attachment', (string)$recordingUrlObjectStorage->lastResponseContentDisposition, 'a download stays an attachment');
    }

    protected function buildBlob(string $contentType): DriveBlob
    {
        /** @var DriveBlob $driveBlob */
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->storageKey = 'blobs/test';
        $driveBlob->contentType = $contentType;
        return $driveBlob;
    }
}
