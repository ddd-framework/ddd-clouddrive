<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveImportsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Domain\CloudDrive\Services\Files\CloudDriveFileSourceService;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use ReflectionClass;

/** The import on the services of a test; the bytes it would store are kept by file name */
class FileImportDriveImportsService extends DriveImportsService
{
    /** @var string[] The stored bytes by file name */
    public array $storedBytesByFileName = [];

    /** @var int[] The ids of the nodes whose expiry was stored */
    public array $nodeIdsWithStoredExpiry = [];

    protected int $nextBlobId = 100;

    public function __construct(
        public FileSourcesService $fileSourcesService,
        public ScriptedFileFlowPolicyService $scriptedFileFlowPolicyService,
        public FileImportDriveNodesService $fileImportDriveNodesService,
        public FileImportDriveSpacesService $fileImportDriveSpacesService,
        public FileImportDriveVersionsService $fileImportDriveVersionsService,
        public FileImportDriveChangesService $fileImportDriveChangesService,
        public FileImportDriveWorkingCopiesService $fileImportDriveWorkingCopiesService
    ) {
    }

    protected function storeBytes(DriveSpace $driveSpace, string $fileName, string $bytes, ?string $contentType): DriveBlob
    {
        $this->storedBytesByFileName[$fileName] = $bytes;
        /** @var DriveBlob $driveBlob */
        $driveBlob = (new ReflectionClass(DriveBlob::class))->newInstanceWithoutConstructor();
        $driveBlob->id = $this->nextBlobId++;
        return $driveBlob;
    }

    protected function storeExpiryOfNode(DriveNode $driveNode): void
    {
        $this->nodeIdsWithStoredExpiry[] = (int)$driveNode->id;
    }

    public function getMaxImportBytes(): int
    {
        return static::DEFAULT_MAX_IMPORT_BYTES;
    }

    protected function getFileSourcesService(): FileSourcesService
    {
        return $this->fileSourcesService;
    }

    protected function getFileFlowPolicyService(): FileFlowPolicyService
    {
        return $this->scriptedFileFlowPolicyService;
    }

    protected function getCloudDriveFileSourceService(): CloudDriveFileSourceService
    {
        return new FileSourceTestCloudDriveFileSourceService($this->fileImportDriveNodesService, $this->fileImportDriveSpacesService);
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->fileImportDriveSpacesService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->fileImportDriveNodesService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return new FixedStorageDriveBlobsService(new \DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage(sys_get_temp_dir()));
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        return $this->fileImportDriveVersionsService;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        return $this->fileImportDriveChangesService;
    }

    protected function getDriveWorkingCopiesService(): DriveWorkingCopiesService
    {
        return $this->fileImportDriveWorkingCopiesService;
    }
}
