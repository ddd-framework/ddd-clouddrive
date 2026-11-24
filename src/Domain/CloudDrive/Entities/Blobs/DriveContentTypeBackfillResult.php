<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Blobs;

use DDD\Domain\Base\Entities\ValueObject;

/** What one run of the content type backfill did ({@see \DDD\Domain\CloudDrive\Services\DriveMaintenanceService::backfillContentTypes()}) */
class DriveContentTypeBackfillResult extends ValueObject
{
    /** @var int Blobs with an unknown stored type that were read */
    public int $checkedBlobCount = 0;

    /** @var int Blobs that got (or, in a dry run, would get) a type from their bytes */
    public int $updatedBlobCount = 0;

    /** @var int Blobs whose bytes did not tell more than `application/octet-stream` */
    public int $unchangedBlobCount = 0;

    /** @var int Blobs whose bytes the store could not give */
    public int $unreadableBlobCount = 0;

    /** @var bool true when more blobs with an unknown type remain after the limit */
    public bool $hasMore = false;

    public bool $isDryRun = false;

    /** @var DriveContentTypeCounts The detected types of the updated blobs */
    public DriveContentTypeCounts $detectedContentTypes;

    public function __construct()
    {
        $this->detectedContentTypes = new DriveContentTypeCounts();
    }
}
