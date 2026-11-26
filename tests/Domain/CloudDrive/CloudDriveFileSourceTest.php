<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentDelivery;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Services\Files\FileSource;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveSpacesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileSourceTestCloudDriveFileSourceService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FixedDeliveryDriveContentGrantsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FixedStorageDriveBlobsService;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The drive as a file source: `drive/<nodeId>` is a live file with a released version in a space the caller reads;
 * its bytes come from the store after the checks of a download.
 */
class CloudDriveFileSourceTest extends TestCase
{
    protected string $objectStoreDirectory;

    protected FilesystemObjectStorage $filesystemObjectStorage;

    protected FileImportDriveNodesService $nodes;

    protected FileImportDriveSpacesService $spaces;

    protected FixedDeliveryDriveContentGrantsService $contentGrants;

    protected FileSourceTestCloudDriveFileSourceService $driveSource;

    protected function setUp(): void
    {
        $this->objectStoreDirectory = sys_get_temp_dir() . '/clouddrive-file-source-' . bin2hex(random_bytes(6));
        mkdir($this->objectStoreDirectory, 0777, true);
        $this->filesystemObjectStorage = new FilesystemObjectStorage($this->objectStoreDirectory);
        $this->nodes = new FileImportDriveNodesService();
        $this->spaces = new FileImportDriveSpacesService();
        $this->contentGrants = new FixedDeliveryDriveContentGrantsService();
        $this->driveSource = new FileSourceTestCloudDriveFileSourceService(
            $this->nodes,
            $this->spaces,
            $this->contentGrants,
            new FixedStorageDriveBlobsService($this->filesystemObjectStorage)
        );
        $this->spaces->addSpace(1, 'Own drive');
        $this->spaces->addSpace(2, 'Team Musterstadt', DriveSpace::AUDIENCE_SHARED);
        $this->nodes->addNode(1, null, '', DriveNode::NODE_TYPE_FOLDER, 10);
        $this->nodes->addNode(1, 10, 'reports', DriveNode::NODE_TYPE_FOLDER, 11);
        $this->nodes->addNode(1, 10, 'customer-files', DriveNode::NODE_TYPE_FOLDER, 12);
        $this->nodes->addNode(1, 12, 'ticket-7', DriveNode::NODE_TYPE_FOLDER, 13);
        $this->nodes->addNode(2, null, '', DriveNode::NODE_TYPE_FOLDER, 20);
        // a space the caller may not read
        $this->nodes->addNode(3, null, '', DriveNode::NODE_TYPE_FOLDER, 30);
        $this->addFile(101, 1, 11, 'Plan.pdf', 'application/pdf');
        $this->addFile(102, 1, 13, 'photo.jpg', 'image/jpeg');
        $this->addFile(201, 2, 20, 'Logo.png', 'image/png');
        $this->addFile(301, 3, 30, 'Secret.pdf', 'application/pdf');
    }

    protected function tearDown(): void
    {
        $storedEntries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->objectStoreDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($storedEntries as $storedEntry) {
            $storedEntry->isDir() ? rmdir($storedEntry->getPathname()) : unlink($storedEntry->getPathname());
        }
        rmdir($this->objectStoreDirectory);
    }

    protected function addFile(int $driveNodeId, int $driveSpaceId, int $parentNodeId, string $name, string $contentType): DriveNode
    {
        $driveNode = $this->nodes->addNode($driveSpaceId, $parentNodeId, $name, DriveNode::NODE_TYPE_FILE, $driveNodeId);
        $driveNode->contentType = $contentType;
        $driveNode->sizeBytes = 1234;
        $driveNode->sha256 = str_repeat('a', 64);
        $driveNode->currentVersionId = $driveNodeId * 10;
        $driveNode->latestVersionId = $driveNodeId * 10;
        return $driveNode;
    }

    public function testTheSourceIsNamedDriveAndImplementsThePort(): void
    {
        self::assertInstanceOf(FileSource::class, $this->driveSource);
        self::assertSame('drive', $this->driveSource->getSourceName());
    }

    public function testFactsOfAReadableFileComeFromItsNodeAndSpace(): void
    {
        $this->nodes->adminLinkTemplate = '/admin/drive?file={nodeId}';
        $fileFacts = $this->driveSource->findFilesFacts(['101'])->first();
        self::assertSame('drive/101', $fileFacts->fileReference->toString());
        self::assertSame('Plan.pdf', $fileFacts->name);
        self::assertSame(FileFamily::PDF, $fileFacts->family);
        self::assertSame('application/pdf', $fileFacts->contentType);
        self::assertSame(1234, $fileFacts->sizeBytes);
        self::assertSame(FileAudience::INTERNAL, $fileFacts->audience);
        self::assertFalse($fileFacts->isContentUntrusted);
        self::assertSame('Own drive', $fileFacts->containerLabel);
        self::assertSame('/admin/drive?file=101', $fileFacts->openLink);
    }

    public function testTheAudienceFollowsTheSpaceAndThePersonalFolder(): void
    {
        $sharedFacts = $this->driveSource->findFilesFacts(['201'])->first();
        self::assertSame(FileAudience::SHARED, $sharedFacts->audience);
        // a file people outside the staff can write is third-party content
        self::assertTrue($sharedFacts->isContentUntrusted);
        self::assertSame(FileAudience::PERSONAL, $this->driveSource->findFilesFacts(['102'])->first()->audience);
        self::assertSame(FileAudience::PERSONAL, $this->driveSource->findAudienceOfPath($this->spaces->readableSpacesById[2], 'customer-files/ticket-7'));
        self::assertSame(FileAudience::INTERNAL, $this->driveSource->findAudienceOfPath($this->spaces->readableSpacesById[1], 'customer-files-old/x'));
    }

    public function testWhatTheCallerMayNotReadDoesNotExist(): void
    {
        $this->nodes->driveNodesById[101]->trashedAt = new DateTime();
        $pendingNode = $this->addFile(103, 1, 11, 'Pending.pdf', 'application/pdf');
        $pendingNode->currentVersionId = null;
        $fileFactsSet = $this->driveSource->findFilesFacts(['101', '103', '11', '301', '999', 'abc', '0', '../101', '201']);
        self::assertSame(1, $fileFactsSet->count());
        self::assertSame('drive/201', $fileFactsSet->first()->fileReference->toString());
        foreach (['101', '103', '11', '301', '999', 'abc'] as $id) {
            try {
                $this->driveSource->readFileContent($id, 1000);
                self::fail('drive/' . $id . ' was read');
            } catch (NotFoundException $notFoundException) {
                self::assertStringContainsString('drive/' . $id, $notFoundException->getMessage());
            }
        }
        self::assertSame([], $this->contentGrants->askedPurposes);
    }

    public function testTheBytesComeFromTheStoreAfterTheChecksOfADownload(): void
    {
        $this->filesystemObjectStorage->putObject('1/2026/7-abc', 'the released bytes', 'application/pdf');
        // an object of the store that belongs to no drive file: never listed, never read
        $this->filesystemObjectStorage->putObject('agent-voice-samples/voice-1', 'not a drive file', 'audio/wav');
        $driveContentDelivery = new DriveContentDelivery();
        $driveContentDelivery->storageKey = '1/2026/7-abc';
        $driveContentDelivery->fileName = 'Plan.pdf';
        $driveContentDelivery->sizeBytes = 18;
        $driveContentDelivery->contentType = 'application/pdf';
        $driveContentDelivery->sha256 = str_repeat('b', 64);
        $this->contentGrants->driveContentDelivery = $driveContentDelivery;

        $fileContent = $this->driveSource->readFileContent('101', 1000);
        self::assertSame('the released bytes', $fileContent->getContent());
        self::assertSame(18, $fileContent->fileFacts->sizeBytes);
        self::assertSame(str_repeat('b', 64), $fileContent->fileFacts->sha256);
        self::assertSame([DriveContentGrant::PURPOSE_DOWNLOAD], $this->contentGrants->askedPurposes);

        try {
            $this->driveSource->readFileContent('101', 17);
            self::fail('a file over the cap was read');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('at most 17', $badRequestException->getMessage());
        }
    }

    public function testARefusalOfTheDownloadChecksIsTheSourcesRefusal(): void
    {
        $this->contentGrants->refusal = new ForbiddenException('This version is not released.');
        $this->expectException(ForbiddenException::class);
        $this->driveSource->readFileContent('101', 1000);
    }
}
