<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Media\DriveMediaImport;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveMediaLibraryBridgeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The bridge into the media library: a port the host implements; the default has no library */
class DriveMediaLibraryBridgeTest extends TestCase
{
    public function testTheDefaultHasNoLibraryAndRefuses(): void
    {
        $driveMediaLibraryBridgeService = new DriveMediaLibraryBridgeService();
        self::assertFalse($driveMediaLibraryBridgeService->isMediaLibraryAvailable());
        $driveMediaImportResult = $driveMediaLibraryBridgeService->importFileIntoMediaLibrary($this->createImageFile('image/png'), new DriveMediaImport());
        self::assertFalse($driveMediaImportResult->isImported());
        self::assertStringContainsString('no media library', (string)$driveMediaImportResult->refusalReason);
    }

    public function testOnlyAReleasedRasterImageGoesIntoTheLibrary(): void
    {
        $driveMediaLibraryBridgeService = new DriveMediaLibraryBridgeService();
        self::assertNull($driveMediaLibraryBridgeService->findImportRefusalReason($this->createImageFile('image/webp')));
        self::assertStringContainsString('image/svg+xml', (string)$driveMediaLibraryBridgeService->findImportRefusalReason($this->createImageFile('image/svg+xml')));
        $pendingImage = $this->createImageFile('image/png');
        $pendingImage->currentVersionId = null;
        self::assertStringContainsString('no released version', (string)$driveMediaLibraryBridgeService->findImportRefusalReason($pendingImage));
        $folderNode = $this->createImageFile('image/png');
        $folderNode->nodeType = DriveNode::NODE_TYPE_FOLDER;
        self::assertSame('only a file can go into the media library', $driveMediaLibraryBridgeService->findImportRefusalReason($folderNode));
    }

    protected function createImageFile(string $contentType): DriveNode
    {
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = 812;
        $driveNode->nodeType = DriveNode::NODE_TYPE_FILE;
        $driveNode->name = 'picture';
        $driveNode->contentType = $contentType;
        $driveNode->currentVersionId = 4711;
        return $driveNode;
    }
}
