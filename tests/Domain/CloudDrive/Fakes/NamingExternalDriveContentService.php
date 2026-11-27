<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalFolderNames;
use DDD\Domain\CloudDrive\Services\AI\External\ExternalDriveContentService;

/** Opens the filesystem naming of the external drive mirror to a test */
class NamingExternalDriveContentService extends ExternalDriveContentService
{
    public function nameInFolder(AgentConnectionDriveItem $driveItem, DriveExternalFolderNames $folderNames): string
    {
        $filesystemName = $this->buildUniqueFilesystemName($driveItem, $folderNames);
        $folderNames->take($filesystemName);
        return $filesystemName;
    }
}
