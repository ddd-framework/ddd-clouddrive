<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\AI\CloudDriveAgentDriveStoreService;
use DDD\Domain\CloudDrive\Services\DriveImportsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;

/** The agent's drive store on the services of the import tests */
class ImportingCloudDriveAgentDriveStoreService extends CloudDriveAgentDriveStoreService
{
    public function __construct(
        public FileImportDriveImportsService $fileImportDriveImportsService,
        public ?DriveWorkingCopy $ownWorkingCopy = null
    ) {
    }

    public function findOwnWorkingCopyOfCall(AgentToolCallProvenance $callProvenance, DriveSpace $ownDriveSpace): ?DriveWorkingCopy
    {
        return $this->ownWorkingCopy;
    }

    protected function getDriveImportsService(): DriveImportsService
    {
        return $this->fileImportDriveImportsService;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        return $this->fileImportDriveImportsService->fileImportDriveSpacesService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        return $this->fileImportDriveImportsService->fileImportDriveNodesService;
    }
}
