<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveContentTypeCounts;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RecordingUrlDriveBlobsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RecordingUrlObjectStorage;
use PHPUnit\Framework\TestCase;

/**
 * The stored content type comes from the bytes: a PDF is a PDF only when its bytes say so, a name only tells text
 * subtypes and office formats apart, a writer's claim never makes bytes an image, a PDF or text.
 */
class DriveContentTypeDetectionTest extends TestCase
{
    public function testTheBytesDecideTheType(): void
    {
        $pngBytes = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAC0lEQVR4nGNgwAQAABQAAX3+Hu4AAAAASUVORK5CYII=', true);
        $pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $zipBytes = "PK\x03\x04" . str_repeat("\x00", 26) . 'content.bin' . str_repeat('x', 64);
        $cases = [
            'pdf without name or claim' => [$pdfBytes, null, null, 'application/pdf'],
            'pdf named .bin' => [$pdfBytes, 'export.bin', 'application/octet-stream', 'application/pdf'],
            'png' => [$pngBytes, null, null, 'image/png'],
            'png named .pdf' => [$pngBytes, 'fake.pdf', 'application/pdf', 'image/png'],
            'markdown by name' => ["# Title\n\nSome text.\n", 'Notes.md', null, 'text/markdown'],
            'plain text' => ["just some words\n", 'notes.txt', null, 'text/plain'],
            'text with declared csv' => ["a,b\n1,2\n", null, 'text/csv', 'text/csv'],
            'text claiming pdf' => ["just some words\n", 'notes', 'application/pdf', 'text/plain'],
            'html' => ["<!DOCTYPE html><html><head><title>x</title></head><body><script>x()</script></body></html>\n", 'page.md', null, 'text/html'],
            'zip named .pptx' => [$zipBytes, 'Deck.pptx', null, 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'zip without name' => [$zipBytes, null, null, 'application/zip'],
            'empty markdown' => ['', 'Empty.md', null, 'text/markdown'],
            'empty without name' => ['', null, null, 'application/octet-stream'],
        ];
        foreach ($cases as $label => [$bytes, $fileName, $declaredContentType, $expectedContentType]) {
            self::assertSame($expectedContentType, DriveBlobsService::detectContentTypeOfBytes($bytes, $fileName, $declaredContentType), $label);
        }
    }

    public function testEveryPassiveTextSubtypeLibmagicNamesIsTreatedAsText(): void
    {
        $chooseContentType = new \ReflectionMethod(DriveBlobsService::class, 'chooseContentTypeFromSniffedType');
        $cases = [
            ['text/x-objective-c', 'brand.typ', null, 'text/plain'],
            ['text/x-c', 'notes.md', null, 'text/markdown'],
            ['text/x-c++', 'data.csv', null, 'text/csv'],
            ['text/x-python', 'readme.txt', null, 'text/plain'],
            ['text/x-markdown', 'unknown.ext', null, 'text/x-markdown'],
            ['text/x-shellscript', 'run', 'text/markdown', 'text/markdown'],
            ['text/x-shellscript', 'run', 'text/html', 'text/plain'],
            ['text/html', 'page.md', null, 'text/html'],
            ['text/xml', 'feed.txt', null, 'text/xml'],
        ];
        foreach ($cases as [$sniffedContentType, $fileName, $declaredContentType, $expectedContentType]) {
            self::assertSame($expectedContentType, $chooseContentType->invoke(null, $sniffedContentType, $declaredContentType, $fileName), $sniffedContentType . ' / ' . $fileName);
        }
    }

    public function testAnUnrefinedTextGuessCountsAsUnknownSoItIsReadAgain(): void
    {
        self::assertTrue(DriveBlobsService::isContentTypeUnknown('text/x-objective-c'));
        self::assertFalse(DriveBlobsService::isContentTypeUnknown('text/x-markdown'));
        self::assertFalse(DriveBlobsService::isContentTypeUnknown('text/plain'));
        self::assertFalse(DriveBlobsService::isContentTypeUnknown('text/html'));
    }

    public function testUnnamedBinaryKeepsOnlyAnApplicationClaimThatIsNoPdfOrText(): void
    {
        $binaryBytes = random_bytes(64) . "\x00\x01\x02";
        $unnamedType = DriveBlobsService::detectContentTypeOfBytes($binaryBytes, null, null);
        if ($unnamedType !== 'application/octet-stream') {
            self::markTestSkipped('libmagic names these random bytes ' . $unnamedType);
        }
        self::assertSame('application/vnd.custom-format', DriveBlobsService::detectContentTypeOfBytes($binaryBytes, null, 'application/vnd.custom-format'));
        self::assertSame('application/octet-stream', DriveBlobsService::detectContentTypeOfBytes($binaryBytes, null, 'application/pdf'));
        self::assertSame('application/octet-stream', DriveBlobsService::detectContentTypeOfBytes($binaryBytes, null, 'application/json'));
        self::assertSame('application/octet-stream', DriveBlobsService::detectContentTypeOfBytes($binaryBytes, null, 'image/png'));
    }

    public function testAnUnknownStoredTypeIsReadFromTheStoredBytesAndOpensInline(): void
    {
        $storageDirectory = sys_get_temp_dir() . '/drive-content-type-' . bin2hex(random_bytes(4));
        $recordingUrlObjectStorage = new RecordingUrlObjectStorage($storageDirectory);
        $driveBlobsService = new RecordingUrlDriveBlobsService($recordingUrlObjectStorage);
        $pdfBytes = "%PDF-1.4\n" . str_repeat('1 0 obj<<>>endobj ', 50) . "\n%%EOF\n";
        $recordingUrlObjectStorage->putObject('blobs/agent.pdf', $pdfBytes, null);
        $driveBlob = new DriveBlob();
        $driveBlob->storageKey = 'blobs/agent.pdf';
        $driveBlob->sizeBytes = strlen($pdfBytes);
        $driveBlob->contentType = 'application/octet-stream';

        self::assertTrue(DriveBlobsService::isContentTypeUnknown($driveBlob->contentType));
        $detectedContentType = $driveBlobsService->readContentTypeOfStoredBlob($driveBlob, 'Report.pdf');
        self::assertSame('application/pdf', $detectedContentType);
        self::assertSame('application/pdf', (new DriveContentGrantsService())->resolveInlineContentType($detectedContentType));

        $driveBlob->storageKey = 'blobs/missing';
        self::assertNull($driveBlobsService->readContentTypeOfStoredBlob($driveBlob, 'Report.pdf'), 'bytes the store cannot give');
    }

    public function testKnownTypesAreNotReadAgain(): void
    {
        self::assertTrue(DriveBlobsService::isContentTypeUnknown(null));
        self::assertTrue(DriveBlobsService::isContentTypeUnknown(''));
        self::assertTrue(DriveBlobsService::isContentTypeUnknown('application/octet-stream'));
        self::assertFalse(DriveBlobsService::isContentTypeUnknown('application/pdf'));
        self::assertFalse(DriveBlobsService::isContentTypeUnknown('text/markdown; charset=utf-8'));
    }

    public function testTheBackfillCountsPerType(): void
    {
        $driveContentTypeCounts = new DriveContentTypeCounts();
        $driveContentTypeCounts->countContentType('application/pdf');
        $driveContentTypeCounts->countContentType('application/pdf');
        $driveContentTypeCounts->countContentType('text/markdown');
        $countsByType = [];
        foreach ($driveContentTypeCounts->getElements() as $driveContentTypeCount) {
            $countsByType[$driveContentTypeCount->contentType] = $driveContentTypeCount->blobCount;
        }
        self::assertSame(['application/pdf' => 2, 'text/markdown' => 1], $countsByType);
    }
}
