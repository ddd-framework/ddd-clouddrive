<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveContentTokensService;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RenditionTestDriveContentGrantsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RenditionTestDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RenditionTestDriveOfficeRenditionsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveBlobsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveContentStreamsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveSpacesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveVersionsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

/**
 * The stage of the rendition tests: a filesystem object store in a temporary directory, the drive's services in
 * memory, one released file (node 7, version 70, blob 700) and ZIP containers built on the spot.
 */
abstract class DriveOfficeRenditionTestCase extends TestCase
{
    public const string DOCX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const string BLOB_STORAGE_KEY = '1/2026/700-test';

    protected string $rootDirectory;

    protected FilesystemObjectStorage $objectStorage;

    protected TransferTestDriveSpacesService $driveSpacesService;

    protected TransferTestDriveVersionsService $driveVersionsService;

    protected TransferTestDriveBlobsService $driveBlobsService;

    protected RenditionTestDriveNodesService $driveNodesService;

    protected RenditionTestDriveContentGrantsService $driveContentGrantsService;

    protected RenditionTestDriveOfficeRenditionsService $driveOfficeRenditionsService;

    protected TransferTestDriveContentStreamsService $driveContentStreamsService;

    protected function setUp(): void
    {
        $_ENV[DriveContentTokensService::SECRET_ENV_NAME] = 'test-secret-of-the-rendition-tests';
        $this->rootDirectory = sys_get_temp_dir() . '/clouddrive-rendition-' . bin2hex(random_bytes(4));
        mkdir($this->rootDirectory . '/scratch', 0700, true);
        $this->objectStorage = new FilesystemObjectStorage($this->rootDirectory . '/store');
        $this->driveSpacesService = (new ReflectionClass(TransferTestDriveSpacesService::class))->newInstanceWithoutConstructor();
        $this->driveSpacesService->isReadRevoked = false;
        $this->driveVersionsService = new TransferTestDriveVersionsService();
        $this->driveBlobsService = new TransferTestDriveBlobsService($this->objectStorage, $this->driveSpacesService);
        $this->driveNodesService = new RenditionTestDriveNodesService();
        $this->driveContentGrantsService = new RenditionTestDriveContentGrantsService($this->driveSpacesService, $this->driveVersionsService, $this->driveBlobsService);
        $this->driveContentGrantsService->testDriveNodesService = $this->driveNodesService;
        $this->driveOfficeRenditionsService = new RenditionTestDriveOfficeRenditionsService($this->driveContentGrantsService, $this->driveBlobsService, $this->driveNodesService);
        $this->driveContentGrantsService->testDriveOfficeRenditionsService = $this->driveOfficeRenditionsService;
        $this->driveContentStreamsService = new TransferTestDriveContentStreamsService($this->driveContentGrantsService, $this->driveNodesService);
    }

    protected function tearDown(): void
    {
        unset($_ENV[DriveContentTokensService::SECRET_ENV_NAME]);
        if (isset($this->rootDirectory)) {
            IsolatedProcess::removeWorkingDirectory($this->rootDirectory);
        }
    }

    /**
     * The bytes of a ZIP container with the given entries.
     * @param array<string, string> $entries content by entry name
     */
    protected function buildContainer(array $entries): string
    {
        $containerPath = $this->rootDirectory . '/scratch/container-' . bin2hex(random_bytes(4)) . '.zip';
        $zipArchive = new ZipArchive();
        self::assertTrue($zipArchive->open($containerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $entryName => $entryContent) {
            $zipArchive->addFromString($entryName, $entryContent);
        }
        $zipArchive->close();
        return (string)file_get_contents($containerPath);
    }

    protected function buildDocx(): string
    {
        return $this->buildContainer([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
        ]);
    }

    protected function writeScratchFile(string $bytes, string $extension): string
    {
        $scratchFilePath = $this->rootDirectory . '/scratch/source-' . bin2hex(random_bytes(4)) . '.' . $extension;
        file_put_contents($scratchFilePath, $bytes);
        return $scratchFilePath;
    }

    /** A released file (node 7, version 70, blob 700) holding `$content` */
    protected function storeFile(string $name, string $content, ?string $contentType = self::DOCX_CONTENT_TYPE, string $state = DriveBlob::STATE_RELEASED): DriveNode
    {
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->id = 700;
        $driveBlob->driveSpaceId = 1;
        $driveBlob->state = $state;
        $driveBlob->storageKey = self::BLOB_STORAGE_KEY;
        $driveBlob->sizeBytes = strlen($content);
        $driveBlob->contentType = $contentType;
        $driveBlob->sha256 = hash('sha256', $content);
        $this->driveBlobsService->blobsById[700] = $driveBlob;
        $this->objectStorage->putObject(self::BLOB_STORAGE_KEY, $content);

        $driveFileVersion = (new ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $driveFileVersion->id = 70;
        $driveFileVersion->driveNodeId = 7;
        $driveFileVersion->driveBlobId = 700;
        $this->driveVersionsService->versionsById[70] = $driveFileVersion;

        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = 7;
        $driveNode->driveSpaceId = 1;
        $driveNode->nodeType = DriveNode::NODE_TYPE_FILE;
        $driveNode->name = $name;
        $driveNode->currentVersionId = 70;
        $driveNode->sizeBytes = strlen($content);
        $driveNode->contentType = $contentType;
        $this->driveNodesService->nodesById[7] = $driveNode;
        return $driveNode;
    }

    protected function buildFileNode(string $name, int $sizeBytes = 1000, ?string $contentType = null, string $nodeType = DriveNode::NODE_TYPE_FILE): DriveNode
    {
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = 9;
        $driveNode->driveSpaceId = 1;
        $driveNode->nodeType = $nodeType;
        $driveNode->name = $name;
        $driveNode->sizeBytes = $sizeBytes;
        $driveNode->contentType = $contentType;
        return $driveNode;
    }

    protected function readStoredMarker(string $markerStorageKey): ?array
    {
        $markerContent = $this->objectStorage->getObjectContent($markerStorageKey);
        return $markerContent !== null ? json_decode($markerContent, true) : null;
    }

    protected function escapeArgument(string $argument): string
    {
        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }
}
