<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * A move of files and folders to another space as a job (setting `CloudDrive.settings.asyncTransfer`): copy in the
 * store, write in the target, purge the source. Routed like the ingest to the transport `drive_ingest`.
 */
class DriveSpaceTransferMessage extends AppMessage
{
    public static string $messageHandler = DriveSpaceTransferHandler::class;

    public ?int $sourceDriveSpaceId = null;

    /** @var int[] */
    public array $driveNodeIds = [];

    public ?int $targetDriveSpaceId = null;

    public ?int $targetFolderNodeId = null;

    /** @var string The audience the transfer was asked as (its rights and type policy) */
    public string $audience = 'INTERNAL';

    /**
     * @param int[] $driveNodeIds
     */
    public function __construct(?int $sourceDriveSpaceId = null, array $driveNodeIds = [], ?int $targetDriveSpaceId = null, ?int $targetFolderNodeId = null, string $audience = 'INTERNAL')
    {
        parent::__construct();
        $this->sourceDriveSpaceId = $sourceDriveSpaceId;
        $this->driveNodeIds = array_values(array_map('intval', $driveNodeIds));
        $this->targetDriveSpaceId = $targetDriveSpaceId;
        $this->targetFolderNodeId = $targetFolderNodeId;
        $this->audience = $audience;
    }
}
