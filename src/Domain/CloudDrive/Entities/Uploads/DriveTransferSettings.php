<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Uploads;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * How file bytes travel between a client and the backend (`CloudDrive.settings.transfer`): every byte goes through the
 * application, never directly to the object store. A missing key takes its default here (an application file
 * `config/app/CloudDrive/settings.php` replaces the module's whole), the deprecated keys `multipartPartBytes` and
 * `downloadUrlLifetimeSeconds` are read when `transfer` does not set the value.
 */
class DriveTransferSettings extends ValueObject
{
    public const int DEFAULT_PART_BYTES = 16 * 1024 * 1024;

    public const int MIN_PART_BYTES = 5 * 1024 * 1024;

    public const int DEFAULT_TOKEN_LIFETIME_SECONDS = 600;

    public const int MIN_TOKEN_LIFETIME_SECONDS = 30;

    public const int DEFAULT_STREAM_CHUNK_BYTES = 1024 * 1024;

    public const string DEFAULT_CONTENT_URL_PREFIX = '/api/public/drive/content';

    /** @var int Size of one part of a multipart upload (the last one smaller); one request carries one part */
    public int $partBytes = self::DEFAULT_PART_BYTES;

    /** @var int An upload up to this size is sent in one request; a larger one in parts */
    public int $singlePartMaxBytes = self::DEFAULT_PART_BYTES;

    /** @var int Lifetime of a content URL (download, preview, thumbnail) in seconds */
    public int $tokenLifetimeSeconds = self::DEFAULT_TOKEN_LIFETIME_SECONDS;

    /** @var int Size of the chunks a download is streamed in */
    public int $streamChunkBytes = self::DEFAULT_STREAM_CHUNK_BYTES;

    /** @var string Where the host mounted `CloudDriveContentControllerTrait` (path or absolute URL, no trailing slash) */
    public string $contentUrlPrefix = self::DEFAULT_CONTENT_URL_PREFIX;

    public static function fromConfig(): static
    {
        $transferConfig = Config::get('CloudDrive.settings.transfer');
        $transferConfig = is_array($transferConfig) ? $transferConfig : [];
        $driveTransferSettings = new static();
        $driveTransferSettings->partBytes = max(
            self::MIN_PART_BYTES,
            (int)($transferConfig['partBytes'] ?? Config::get('CloudDrive.settings.multipartPartBytes') ?? self::DEFAULT_PART_BYTES)
        );
        $driveTransferSettings->singlePartMaxBytes = max(0, (int)($transferConfig['singlePartMaxBytes'] ?? $driveTransferSettings->partBytes));
        $driveTransferSettings->tokenLifetimeSeconds = max(
            self::MIN_TOKEN_LIFETIME_SECONDS,
            (int)($transferConfig['tokenLifetimeSeconds'] ?? Config::get('CloudDrive.settings.downloadUrlLifetimeSeconds') ?? self::DEFAULT_TOKEN_LIFETIME_SECONDS)
        );
        $driveTransferSettings->streamChunkBytes = max(8192, (int)($transferConfig['streamChunkBytes'] ?? self::DEFAULT_STREAM_CHUNK_BYTES));
        $contentUrlPrefix = trim((string)($transferConfig['contentUrlPrefix'] ?? ''));
        $driveTransferSettings->contentUrlPrefix = $contentUrlPrefix !== '' ? rtrim($contentUrlPrefix, '/') : self::DEFAULT_CONTENT_URL_PREFIX;
        return $driveTransferSettings;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic('transfer');
    }
}
