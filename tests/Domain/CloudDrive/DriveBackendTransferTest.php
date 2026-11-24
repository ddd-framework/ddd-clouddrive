<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Content\DriveContentToken;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveContentTokensService;
use DDD\Domain\CloudDrive\Services\DriveMaintenanceService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveBlobsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveContentGrantsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveContentStreamsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveSpacesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\TransferTestDriveVersionsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every byte through the backend: signed content URLs (rights and scan state checked on every fetch, ranges),
 * uploads in one request or in resumable parts streamed into the store, the sweep of unfinished multipart uploads,
 * and no response that names the store.
 */
class DriveBackendTransferTest extends TestCase
{
    public const string STORE_HOST = 'store-host.internal.example';

    protected string $rootDirectory;

    protected TransferTestDriveSpacesService $driveSpacesService;

    protected TransferTestDriveVersionsService $driveVersionsService;

    protected TransferTestDriveBlobsService $driveBlobsService;

    protected TransferTestDriveContentGrantsService $driveContentGrantsService;

    protected TransferTestDriveContentStreamsService $driveContentStreamsService;

    protected function setUp(): void
    {
        $_ENV[DriveContentTokensService::SECRET_ENV_NAME] = 'test-secret-of-the-transfer-tests';
        $this->rootDirectory = sys_get_temp_dir() . '/clouddrive-transfer-' . bin2hex(random_bytes(4));
        $this->createServices(new FilesystemObjectStorage($this->rootDirectory));
    }

    protected function tearDown(): void
    {
        unset($_ENV[DriveContentTokensService::SECRET_ENV_NAME]);
        if (is_dir($this->rootDirectory)) {
            exec('rm -r ' . escapeshellarg($this->rootDirectory));
        }
    }

    protected function createServices(\DDD\Infrastructure\ObjectStorage\ObjectStorage $objectStorage): void
    {
        $this->driveSpacesService = (new ReflectionClass(TransferTestDriveSpacesService::class))->newInstanceWithoutConstructor();
        $this->driveSpacesService->isReadRevoked = false;
        $this->driveVersionsService = new TransferTestDriveVersionsService();
        $this->driveBlobsService = new TransferTestDriveBlobsService($objectStorage, $this->driveSpacesService);
        $this->driveContentGrantsService = new TransferTestDriveContentGrantsService($this->driveSpacesService, $this->driveVersionsService, $this->driveBlobsService);
        $driveNodesService = new TransferTestDriveNodesService();
        $this->driveContentStreamsService = new TransferTestDriveContentStreamsService($this->driveContentGrantsService, $driveNodesService);
    }

    /** A released file with `$content` (node 7, version 70, blob 700) */
    protected function storeReleasedFile(string $content, string $contentType = 'application/pdf', string $state = DriveBlob::STATE_RELEASED): DriveNode
    {
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->id = 700;
        $driveBlob->driveSpaceId = 1;
        $driveBlob->state = $state;
        $driveBlob->storageKey = '1/2026/700-test';
        $driveBlob->sizeBytes = strlen($content);
        $driveBlob->contentType = $contentType;
        $driveBlob->sha256 = hash('sha256', $content);
        $this->driveBlobsService->blobsById[700] = $driveBlob;
        $this->driveBlobsService->getObjectStorage()->putObject('1/2026/700-test', $content);

        $driveFileVersion = (new ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $driveFileVersion->id = 70;
        $driveFileVersion->driveNodeId = 7;
        $driveFileVersion->driveBlobId = 700;
        $this->driveVersionsService->versionsById[70] = $driveFileVersion;

        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = 7;
        $driveNode->driveSpaceId = 1;
        $driveNode->nodeType = DriveNode::NODE_TYPE_FILE;
        $driveNode->name = 'Deck.pdf';
        $driveNode->currentVersionId = 70;
        $this->driveContentStreamsService->testDriveNodesService->nodesById[7] = $driveNode;
        return $driveNode;
    }

    protected function extractToken(string $contentUrl): string
    {
        return substr($contentUrl, strrpos($contentUrl, '/') + 1);
    }

    protected function readBody(\Symfony\Component\HttpFoundation\Response $response): string
    {
        ob_start();
        $response->sendContent();
        return (string)ob_get_clean();
    }

    public function testATokenIsRefusedWhenAlteredOrExpired(): void
    {
        $driveContentTokensService = new DriveContentTokensService();
        $driveContentToken = new DriveContentToken();
        $driveContentToken->driveNodeId = 7;
        $driveContentToken->driveFileVersionId = 70;
        $driveContentToken->purpose = DriveContentGrant::PURPOSE_INLINE;
        $driveContentToken->accountId = 3;
        $driveContentToken->audience = 'INTERNAL';
        $driveContentToken->expiresAt = time() + 60;
        $token = $driveContentTokensService->createToken($driveContentToken);

        $readToken = $driveContentTokensService->readToken($token);
        self::assertSame(70, $readToken?->driveFileVersionId);
        self::assertSame(3, $readToken?->accountId);
        self::assertSame(DriveContentGrant::PURPOSE_INLINE, $readToken?->purpose);

        [$payload, $signature] = explode('.', $token);
        $alteredPayload = rtrim(strtr(base64_encode(str_replace('"n":7', '"n":8', (string)base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=');
        self::assertNull($driveContentTokensService->readToken($alteredPayload . '.' . $signature), 'another node');
        self::assertNull($driveContentTokensService->readToken($token, time() + 61), 'expired');
        self::assertNull($driveContentTokensService->readToken('garbage'));
    }

    public function testAGrantIsABackendUrlAndARangeIsStreamed(): void
    {
        $content = str_repeat('0123456789', 1000);
        $driveNode = $this->storeReleasedFile($content);
        $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_INLINE);
        self::assertStringStartsWith('/api/public/drive/content/', $driveContentGrant->url);
        self::assertTrue($driveContentGrant->isInline);

        $request = Request::create($driveContentGrant->url);
        $request->headers->set('Range', 'bytes=10-19');
        $response = $this->driveContentStreamsService->createContentStreamResponse($this->extractToken($driveContentGrant->url), $request);
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 10-19/10000', $response->headers->get('Content-Range'));
        self::assertSame('0123456789', $this->readBody($response));
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string)$response->headers->get('Content-Disposition'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertSame([DriveContentGrant::PURPOSE_INLINE], $this->driveContentStreamsService->recordedAccessPurposes, 'the fetch is the recorded access');
    }

    public function testRevokedRightsRefuseAnIssuedUrl(): void
    {
        $driveNode = $this->storeReleasedFile('content');
        $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
        $this->driveSpacesService->isReadRevoked = true;
        $this->expectException(ForbiddenException::class);
        $this->driveContentStreamsService->createContentStreamResponse($this->extractToken($driveContentGrant->url), Request::create('/'));
    }

    public function testABlobBlockedAfterTheGrantIsRefused(): void
    {
        $driveNode = $this->storeReleasedFile('content');
        $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
        $this->driveBlobsService->blobsById[700]->state = DriveBlob::STATE_BLOCKED;
        try {
            $this->driveContentStreamsService->createContentStreamResponse($this->extractToken($driveContentGrant->url), Request::create('/'));
            self::fail('a blocked blob must not be served');
        } catch (ForbiddenException) {
            self::assertSame([], $this->driveContentStreamsService->recordedAccessPurposes);
        }
    }

    public function testAnUnreleasedBlobGetsNoGrant(): void
    {
        $driveNode = $this->storeReleasedFile('content', 'application/pdf', DriveBlob::STATE_PENDING);
        $this->expectException(ForbiddenException::class);
        $this->driveContentGrantsService->createContentGrant($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD);
    }

    public function testAResumedMultipartUploadSendsOnlyTheMissingPartsAndRetriesAStalledOne(): void
    {
        $partBytes = \DDD\Domain\CloudDrive\Entities\Uploads\DriveTransferSettings::DEFAULT_PART_BYTES;
        $sizeBytes = 2 * $partBytes + 1000;
        $objectStorage = $this->driveBlobsService->getObjectStorage();
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->id = 800;
        $driveBlob->driveSpaceId = 1;
        $driveBlob->state = DriveBlob::STATE_UPLOADING;
        $driveBlob->storageKey = '1/2026/800-test';
        $driveBlob->sizeBytes = $sizeBytes;
        $driveBlob->contentType = 'application/octet-stream';
        $driveBlob->multipartUploadId = $objectStorage->createMultipartUpload('1/2026/800-test');

        $this->driveBlobsService->writeUploadPart($driveBlob, 1, $this->createStream(str_repeat('a', $partBytes)), $partBytes);
        // a stalled part 2 arrived short: refused, the client sends it again
        try {
            $this->driveBlobsService->writeUploadPart($driveBlob, 2, $this->createStream('bb'), 2);
            self::fail('a part of the wrong length must be refused');
        } catch (BadRequestException) {
        }
        $driveUploadReservation = $this->driveBlobsService->buildReservation($driveBlob);
        self::assertTrue($driveUploadReservation->isMultipart);
        self::assertSame([1], $driveUploadReservation->uploadedPartNumbers, 'the resume skips part 1');
        self::assertNull($driveUploadReservation->partRequests, 'no presigned request for a client');
        self::assertNull($driveUploadReservation->uploadRequest);

        $this->driveBlobsService->writeUploadPart($driveBlob, 2, $this->createStream(str_repeat('b', $partBytes)), $partBytes);
        $this->driveBlobsService->writeUploadPart($driveBlob, 2, $this->createStream(str_repeat('c', $partBytes)), $partBytes, base64_encode(md5(str_repeat('c', $partBytes), true)));
        $this->driveBlobsService->writeUploadPart($driveBlob, 3, $this->createStream(str_repeat('d', 1000)), 1000);
        $uploadedParts = $objectStorage->listUploadedParts('1/2026/800-test', (string)$driveBlob->multipartUploadId);
        self::assertSame($sizeBytes, $uploadedParts->getTotalSize());
        self::assertTrue($objectStorage->completeMultipartUpload('1/2026/800-test', (string)$driveBlob->multipartUploadId, $uploadedParts));
        self::assertSame(str_repeat('c', 3), $objectStorage->getObjectContentRange('1/2026/800-test', $partBytes, 3), 'the resent part replaced the first');
    }

    public function testAReserveWithAKnownKeyResumesOnlyAUsableBlobOfTheSameSize(): void
    {
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->sizeBytes = 5000;
        $driveBlob->state = DriveBlob::STATE_UPLOADING;
        $driveBlob->expiresAt = \DDD\Infrastructure\Base\DateTime\DateTime::fromTimestamp(time() + 3600);
        self::assertTrue($this->driveBlobsService->isBlobResumable($driveBlob, 5000), 'an open upload is resumed');
        self::assertFalse($this->driveBlobsService->isBlobResumable($driveBlob, 5001), 'another size is another file');
        $driveBlob->expiresAt = \DDD\Infrastructure\Base\DateTime\DateTime::fromTimestamp(time() - 10);
        self::assertFalse($this->driveBlobsService->isBlobResumable($driveBlob, 5000), 'an expired upload may have lost its parts');
        foreach ([DriveBlob::STATE_FAILED, DriveBlob::STATE_SCAN_FAILED, DriveBlob::STATE_PURGING] as $deadState) {
            $driveBlob->state = $deadState;
            self::assertFalse($this->driveBlobsService->isBlobResumable($driveBlob, 5000), $deadState . ' starts a new blob');
        }
        $driveBlob->state = DriveBlob::STATE_RELEASED;
        self::assertTrue($this->driveBlobsService->isBlobResumable($driveBlob, 5000), 'a finished upload needs no bytes again');
    }

    public function testTheApiSchemaOfAReservationLeavesOutTheRunnersPresignedRequests(): void
    {
        $reflectionClass = new ReflectionClass(\DDD\Domain\CloudDrive\Entities\Uploads\DriveUploadReservation::class);
        foreach (['uploadRequest', 'partRequests'] as $propertyName) {
            self::assertNotEmpty($reflectionClass->getProperty($propertyName)->getAttributes(\DDD\Presentation\Base\OpenApi\Attributes\Ignore::class), $propertyName . ' is no part of the client schema');
        }
        self::assertEmpty($reflectionClass->getProperty('uploadedPartNumbers')->getAttributes(\DDD\Presentation\Base\OpenApi\Attributes\Ignore::class));
    }

    public function testASinglePartUploadIsCheckedAgainstItsMd5(): void
    {
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->id = 801;
        $driveBlob->driveSpaceId = 1;
        $driveBlob->state = DriveBlob::STATE_UPLOADING;
        $driveBlob->storageKey = '1/2026/801-test';
        $driveBlob->sizeBytes = 5;
        $driveBlob->contentType = 'text/plain';
        $driveBlob->contentMd5 = base64_encode(md5('hello', true));
        $driveBlob->multipartUploadId = null;
        try {
            $this->driveBlobsService->writeUploadContent($driveBlob, $this->createStream('world'), 5);
            self::fail('another MD5 must be refused');
        } catch (BadRequestException) {
        }
        $this->driveBlobsService->writeUploadContent($driveBlob, $this->createStream('hello'), 5);
        self::assertSame('hello', $this->driveBlobsService->getObjectStorage()->getObjectContent('1/2026/801-test'));
    }

    public function testTheMaintenanceAbortsUnfinishedMultipartUploads(): void
    {
        $objectStorage = $this->driveBlobsService->getObjectStorage();
        $abandonedUploadId = $objectStorage->createMultipartUpload('1/2026/900-abandoned');
        $runningUploadId = $objectStorage->createMultipartUpload('1/2026/901-running');
        touch($this->rootDirectory . '/' . FilesystemObjectStorage::MULTIPART_DIRECTORY . '/' . $abandonedUploadId, time() - 30 * 3600);

        $driveBlobsService = $this->driveBlobsService;
        $driveMaintenanceService = new class ($driveBlobsService) extends DriveMaintenanceService {
            public function __construct(public TransferTestDriveBlobsService $testDriveBlobsService)
            {
            }

            protected function getDriveBlobsService(): \DDD\Domain\CloudDrive\Services\DriveBlobsService
            {
                return $this->testDriveBlobsService;
            }
        };
        $dryRunLines = $driveMaintenanceService->abortUnfinishedMultipartUploads(true);
        self::assertCount(1, $dryRunLines);
        self::assertCount(2, $objectStorage->listMultipartUploads()->getElements(), 'a dry run aborts nothing');

        $driveMaintenanceService->abortUnfinishedMultipartUploads(false);
        $remainingUploads = $objectStorage->listMultipartUploads();
        self::assertCount(1, $remainingUploads->getElements());
        self::assertSame($runningUploadId, $remainingUploads->first()?->uploadId);
    }

    public function testNoAnswerNamesTheStore(): void
    {
        // a store whose presigned URLs name its host: no answer to a client may carry one
        $this->createServices(new class ($this->rootDirectory) extends FilesystemObjectStorage {
            public function createTemporaryUrl(string $key, int $expiresInSeconds = 3600, ?string $responseContentType = null, ?string $responseContentDisposition = null): string
            {
                return 'https://' . DriveBackendTransferTest::STORE_HOST . '/' . $key;
            }

            public function createTemporaryUploadRequest(string $key, int $expiresInSeconds = 3600, ?string $contentType = null, bool $createOnly = true, ?string $checksumSha256 = null, ?string $contentMd5 = null): \DDD\Infrastructure\ObjectStorage\ObjectStoragePresignedRequest
            {
                $presignedRequest = parent::createTemporaryUploadRequest($key, $expiresInSeconds, $contentType, $createOnly, $checksumSha256, $contentMd5);
                $presignedRequest->url = 'https://' . DriveBackendTransferTest::STORE_HOST . '/' . $key;
                return $presignedRequest;
            }
        });
        $this->driveBlobsService->getObjectStorage()->putObject('1/2026/700-test', '0123456789');
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->id = 700;
        $driveBlob->driveSpaceId = 1;
        $driveBlob->state = DriveBlob::STATE_RELEASED;
        $driveBlob->storageKey = '1/2026/700-test';
        $driveBlob->sizeBytes = 10;
        $driveBlob->contentType = 'image/png';
        $driveBlob->sha256 = hash('sha256', 'x');
        $this->driveBlobsService->blobsById[700] = $driveBlob;
        $driveFileVersion = (new ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $driveFileVersion->id = 70;
        $driveFileVersion->driveNodeId = 7;
        $driveFileVersion->driveBlobId = 700;
        $this->driveVersionsService->versionsById[70] = $driveFileVersion;
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = 7;
        $driveNode->driveSpaceId = 1;
        $driveNode->nodeType = DriveNode::NODE_TYPE_FILE;
        $driveNode->name = 'Picture.png';
        $driveNode->currentVersionId = 70;
        $this->driveContentStreamsService->testDriveNodesService->nodesById[7] = $driveNode;

        foreach (DriveContentGrant::PEOPLE_PURPOSES as $purpose) {
            $driveContentGrant = $this->driveContentGrantsService->createContentGrant($driveNode, $purpose);
            self::assertStringNotContainsString(self::STORE_HOST, (string)json_encode($driveContentGrant->toObject()), $purpose);
            $response = $this->driveContentStreamsService->createContentStreamResponse($this->extractToken($driveContentGrant->url), Request::create('/', 'HEAD'));
            self::assertStringNotContainsString(self::STORE_HOST, (string)$response->headers, $purpose);
        }
        $uploadingBlob = clone $driveBlob;
        $uploadingBlob->state = DriveBlob::STATE_UPLOADING;
        $uploadingBlob->multipartUploadId = null;
        self::assertStringNotContainsString(self::STORE_HOST, (string)json_encode($this->driveBlobsService->buildReservation($uploadingBlob)->toObject()));
        self::assertStringContainsString(self::STORE_HOST, (string)json_encode($this->driveBlobsService->buildReservation($uploadingBlob, null, true)->toObject()), 'only the runner gets presigned requests');
    }

    public function testTheRunnerOfAWorkingCopyKeepsItsPresignedRequests(): void
    {
        $this->createServices(new class ($this->rootDirectory) extends FilesystemObjectStorage {
            public function createTemporaryUrl(string $key, int $expiresInSeconds = 3600, ?string $responseContentType = null, ?string $responseContentDisposition = null): string
            {
                return 'https://' . DriveBackendTransferTest::STORE_HOST . '/' . $key;
            }
        });
        $objectStorage = $this->driveBlobsService->getObjectStorage();
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->id = 802;
        $driveBlob->driveSpaceId = 1;
        $driveBlob->state = DriveBlob::STATE_UPLOADING;
        $driveBlob->storageKey = '1/2026/802-test';
        $driveBlob->sizeBytes = 100 * 1024 * 1024;
        $driveBlob->contentType = 'application/octet-stream';
        $driveBlob->multipartUploadId = $objectStorage->createMultipartUpload('1/2026/802-test');
        $runnerReservation = $this->driveBlobsService->buildReservation($driveBlob, null, true);
        self::assertSame(7, $runnerReservation->partCount);
        self::assertCount(7, $runnerReservation->partRequests?->getElements() ?? []);

        $driveBlob->multipartUploadId = null;
        self::assertNotNull($this->driveBlobsService->buildReservation($driveBlob, null, true)->uploadRequest);

        // the restore of a working copy's blob into the sandbox: a presigned GET of the store (server side)
        $driveBlob->state = DriveBlob::STATE_STAGED;
        $this->driveBlobsService->blobsById[802] = $driveBlob;
        $driveWorkingCopy = (new ReflectionClass(\DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy::class))->newInstanceWithoutConstructor();
        $driveWorkingCopy->driveSpaceId = 1;
        $restoreGrant = $this->driveContentGrantsService->createWorkspaceRestoreGrant($driveWorkingCopy, 802, 'data.bin');
        self::assertStringStartsWith('https://' . self::STORE_HOST . '/', $restoreGrant->url);
    }

    /** @return resource */
    protected function createStream(string $content): mixed
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }
}
