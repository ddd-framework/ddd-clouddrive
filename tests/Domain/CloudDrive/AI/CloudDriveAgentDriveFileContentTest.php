<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Services\Drives\AgentDriveFileContentReader;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentDelivery;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\DocumentReadingCloudDriveAgentDriveStoreService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FixedDeliveryDriveContentGrantsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FixedPathDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FixedStorageDriveBlobsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The agent's document tools read a file through the drive's backend: the download checks of the caller decide, the
 * bytes come from the store by the released version's key, a file over the limit and a folder are refused.
 */
class CloudDriveAgentDriveFileContentTest extends TestCase
{
    protected string $objectStoreDirectory;

    protected FilesystemObjectStorage $filesystemObjectStorage;

    protected FixedDeliveryDriveContentGrantsService $fixedDeliveryDriveContentGrantsService;

    protected DocumentReadingCloudDriveAgentDriveStoreService $documentReadingStore;

    protected function setUp(): void
    {
        $this->objectStoreDirectory = sys_get_temp_dir() . '/clouddrive-agent-content-' . bin2hex(random_bytes(6));
        mkdir($this->objectStoreDirectory, 0777, true);
        $this->filesystemObjectStorage = new FilesystemObjectStorage($this->objectStoreDirectory);
        $this->fixedDeliveryDriveContentGrantsService = new FixedDeliveryDriveContentGrantsService();
        $agentDriveMount = new AgentDriveMount();
        $agentDriveMount->storeName = CloudDriveAgentDriveStoreService::STORE_NAME;
        $agentDriveMount->spaceKey = '12';
        $agentDriveMount->mountName = 'library';
        $agentDriveMount->mode = AgentDriveMount::MODE_READ_ONLY;
        $this->documentReadingStore = new DocumentReadingCloudDriveAgentDriveStoreService(
            $agentDriveMount,
            $this->fixedDeliveryDriveContentGrantsService,
            new FixedStorageDriveBlobsService($this->filesystemObjectStorage),
            new FixedPathDriveNodesService()
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->objectStoreDirectory));
    }

    public function testTheReleasedVersionIsReadFromTheStoreUnderTheDownloadChecks(): void
    {
        $this->documentReadingStore->driveNode = self::buildNode(812, 'Offer.pdf', DriveNode::NODE_TYPE_FILE);
        $this->filesystemObjectStorage->putObject('blobs/ab/abc', '%PDF-1.4 offer');
        $this->fixedDeliveryDriveContentGrantsService->driveContentDelivery = self::buildDelivery('blobs/ab/abc', 'Offer.pdf', 14);

        $agentDriveFileContent = $this->documentReadingStore->readDriveFileContent(self::buildProvenance(), self::buildPath('drivespaces/library/Offer.pdf'), 1000);

        self::assertInstanceOf(AgentDriveFileContentReader::class, $this->documentReadingStore);
        self::assertSame([DriveContentGrant::PURPOSE_DOWNLOAD], $this->fixedDeliveryDriveContentGrantsService->askedPurposes);
        self::assertSame(['drivespaces/library/Offer.pdf'], $this->documentReadingStore->committedPaths);
        self::assertSame('%PDF-1.4 offer', $agentDriveFileContent->getBytes());
        self::assertSame(hash('sha256', '%PDF-1.4 offer'), $agentDriveFileContent->sha256);
        self::assertSame('drive/812', $agentDriveFileContent->reference);
        self::assertSame('drivespaces/library/Offer.pdf', $agentDriveFileContent->workspacePath);
        self::assertSame('application/pdf', $agentDriveFileContent->contentType);
    }

    public function testARefusalOfTheDownloadChecksReachesTheTool(): void
    {
        $this->documentReadingStore->driveNode = self::buildNode(812, 'Offer.pdf', DriveNode::NODE_TYPE_FILE);
        $this->fixedDeliveryDriveContentGrantsService->refusal = new ForbiddenException('The version is not released yet (it waits for its scan).');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('waits for its scan');
        $this->documentReadingStore->readDriveFileContent(self::buildProvenance(), self::buildPath('drivespaces/library/Offer.pdf'), 1000);
    }

    public function testAFileOverTheLimitIsRefusedBeforeItsBytesAreRead(): void
    {
        $this->documentReadingStore->driveNode = self::buildNode(812, 'Big.pdf', DriveNode::NODE_TYPE_FILE);
        $this->fixedDeliveryDriveContentGrantsService->driveContentDelivery = self::buildDelivery('blobs/missing', 'Big.pdf', 30 * 1_048_576);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('up to 25 MB');
        $this->documentReadingStore->readDriveFileContent(self::buildProvenance(), self::buildPath('drivespaces/library/Big.pdf'), 25 * 1_048_576);
    }

    public function testAFolderAndAMissingFileAreNotFound(): void
    {
        $this->documentReadingStore->driveNode = self::buildNode(813, 'Brand', DriveNode::NODE_TYPE_FOLDER);
        try {
            $this->documentReadingStore->readDriveFileContent(self::buildProvenance(), self::buildPath('drivespaces/library/Brand'), 1000);
            self::fail('a folder has no content');
        } catch (NotFoundException $notFoundException) {
            self::assertStringContainsString('is a folder', $notFoundException->getMessage());
        }
        self::assertSame([], $this->fixedDeliveryDriveContentGrantsService->askedPurposes);

        $this->documentReadingStore->driveNode = null;
        $this->expectException(NotFoundException::class);
        $this->documentReadingStore->readDriveFileContent(self::buildProvenance(), self::buildPath('drivespaces/library/Gone.pdf'), 1000);
    }

    protected static function buildPath(string $workspacePath): AgentDrivePath
    {
        $agentDrivePath = new AgentDrivePath();
        $agentDrivePath->argument = $workspacePath;
        $agentDrivePath->pathInMount = substr($workspacePath, strlen('drivespaces/library/'));
        $agentDriveMount = new AgentDriveMount();
        $agentDriveMount->storeName = CloudDriveAgentDriveStoreService::STORE_NAME;
        $agentDriveMount->spaceKey = '12';
        $agentDriveMount->mountName = 'library';
        $agentDrivePath->agentDriveMount = $agentDriveMount;
        return $agentDrivePath;
    }

    protected static function buildNode(int $driveNodeId, string $name, string $nodeType): DriveNode
    {
        /** @var DriveNode $driveNode */
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = $driveNodeId;
        $driveNode->driveSpaceId = 12;
        $driveNode->name = $name;
        $driveNode->nodeType = $nodeType;
        return $driveNode;
    }

    protected static function buildDelivery(string $storageKey, string $fileName, int $sizeBytes): DriveContentDelivery
    {
        $driveContentDelivery = new DriveContentDelivery();
        $driveContentDelivery->storageKey = $storageKey;
        $driveContentDelivery->fileName = $fileName;
        $driveContentDelivery->sizeBytes = $sizeBytes;
        $driveContentDelivery->contentType = 'application/pdf';
        return $driveContentDelivery;
    }

    protected static function buildProvenance(): AgentToolCallProvenance
    {
        /** @var AgentToolCallProvenance $callProvenance */
        $callProvenance = (new ReflectionClass(AgentToolCallProvenance::class))->newInstanceWithoutConstructor();
        return $callProvenance;
    }
}
