<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * The ingest of one new version in a worker (setting `CloudDrive.settings.asyncIngest`): SHA-256, malware scan, dedup,
 * release. The host routes it to the transport `drive_ingest` and gives that transport a consumer.
 */
class DriveIngestVersionMessage extends AppMessage
{
    public static string $messageHandler = DriveIngestVersionHandler::class;

    public ?int $driveFileVersionId = null;

    public bool $isScanned = true;

    public ?string $workingCopyKey = null;

    public function __construct(?int $driveFileVersionId = null, bool $isScanned = true, ?string $workingCopyKey = null)
    {
        parent::__construct();
        $this->driveFileVersionId = $driveFileVersionId;
        $this->isScanned = $isScanned;
        $this->workingCopyKey = $workingCopyKey;
    }
}
