<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersions;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use RuntimeException;

/** Versions of the maintenance dispatch tests: records each ingest call instead of running it */
class DispatchRecordingDriveVersionsService extends DriveVersionsService
{
    /** @var string[] One line per ingest call: "<versionId>:<scanned|unscanned>:<async|in-process>" */
    public array $ingestCalls = [];

    /** @var int[] Version ids whose dispatch fails */
    public array $failingVersionIds = [];

    /** @var DriveFileVersions[] Versions by blob id */
    public array $versionsByBlobId = [];

    public function __construct()
    {
    }

    public function ingestVersion(DriveFileVersion $driveFileVersion, bool $isScanned = true, ?string $workingCopyKey = null, ?bool $async = null): void
    {
        if (in_array((int)$driveFileVersion->id, $this->failingVersionIds, true)) {
            throw new RuntimeException('broker unreachable');
        }
        $this->ingestCalls[] = sprintf('%d:%s:%s', (int)$driveFileVersion->id, $isScanned ? 'scanned' : 'unscanned', $async === true ? 'async' : 'in-process');
    }

    public function findVersionsOfBlob(DriveBlob $driveBlob, ?DriveBlob $otherDriveBlob = null): DriveFileVersions
    {
        return $this->versionsByBlobId[(int)$driveBlob->id] ?? new DriveFileVersions();
    }
}
