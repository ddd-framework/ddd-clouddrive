<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;

/** The rendition service with archive caps a test can exceed with a few bytes */
class SmallCapsDriveOfficeRenditionsService extends DriveOfficeRenditionsService
{
    public const int MAX_ARCHIVE_ENTRIES = 4;

    public const int MAX_ARCHIVE_UNCOMPRESSED_BYTES = 4096;

    public const int MAX_ARCHIVE_COMPRESSION_RATIO = 20;

    public function __construct()
    {
    }
}
