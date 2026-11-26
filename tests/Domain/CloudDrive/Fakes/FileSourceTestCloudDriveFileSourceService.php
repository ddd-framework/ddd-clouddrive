<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\Files\CloudDriveFileSourceService;

/** The drive source on the services of a test */
class FileSourceTestCloudDriveFileSourceService extends CloudDriveFileSourceService
{
    public function __construct(
        public FileImportDriveNodesService $fileImportDriveNodesService,
        public FileImportDriveSpacesService $fileImportDriveSpacesService,
        public ?FixedDeliveryDriveContentGrantsService $fixedDeliveryDriveContentGrantsService = null,
        public ?FixedStorageDriveBlobsService $fixedStorageDriveBlobsService = null
    ) {
    }

    /** @var string[] The personal root folders of the test host (`CloudDrive.settings.personalFolders`) */
    public const array TEST_PERSONAL_FOLDERS = ['customer-files'];

    public function getPersonalFolders(): array
    {
        return static::TEST_PERSONAL_FOLDERS;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->fileImportDriveNodesService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->fileImportDriveSpacesService;
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        return $this->fixedDeliveryDriveContentGrantsService;
    }

    protected function getDriveBlobsService(): DriveBlobsService
    {
        return $this->fixedStorageDriveBlobsService;
    }
}
