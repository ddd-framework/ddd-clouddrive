<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Renditions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The PDF renditions of Office files (`CloudDrive.settings.officeRenditions`): whether the drive offers them, the
 * limits of one conversion and how LibreOffice is started. A missing key takes its default here (an application file
 * `config/app/CloudDrive/settings.php` replaces the module's whole); without the block the feature is off.
 */
class DriveOfficeRenditionSettings extends ValueObject
{
    public const int DEFAULT_MAX_SOURCE_BYTES = 50 * 1024 * 1024;

    public const int DEFAULT_TIMEOUT_SECONDS = 120;

    public const int MIN_TIMEOUT_SECONDS = 10;

    public const int DEFAULT_MAX_PAGES = 300;

    public const string DEFAULT_BINARY = 'soffice';

    /**
     * @var int The address space cap of the converter. LibreOffice reserves far more address space than it uses
     *      (its libraries, a stack and a malloc arena per thread): the 2 GiB default of the isolated process makes
     *      larger documents fail, so the cap is 4 GiB — still a bound for a document that explodes in memory.
     */
    public const int DEFAULT_ADDRESS_SPACE_BYTES = 4 * 1024 * 1024 * 1024;

    /** @var bool Whether the drive offers PDF renditions at all */
    public bool $enabled = false;

    /** @var int A larger file gets no rendition */
    public int $maxSourceBytes = self::DEFAULT_MAX_SOURCE_BYTES;

    /** @var int One conversion is killed after this many seconds */
    public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS;

    /** @var int Only the first pages (sheets' pages, slides) are converted */
    public int $maxPages = self::DEFAULT_MAX_PAGES;

    /** @var string The LibreOffice binary: a plain name on the PATH or an absolute path */
    public string $binary = self::DEFAULT_BINARY;

    /** @var string[] Words put before the binary (a wrapper that switches the uid or sandboxes the process); empty by default */
    public array $commandPrefix = [];

    /** @var int|null The converter's address space cap in bytes (`prlimit --as`, when installed); null = no cap */
    public ?int $addressSpaceBytes = self::DEFAULT_ADDRESS_SPACE_BYTES;

    public static function fromConfig(): static
    {
        $renditionsConfig = Config::get('CloudDrive.settings.officeRenditions');
        return static::fromArray(is_array($renditionsConfig) ? $renditionsConfig : []);
    }

    /**
     * @param array<string, mixed> $renditionsConfig the `officeRenditions` block (internal configuration array)
     */
    public static function fromArray(array $renditionsConfig): static
    {
        $driveOfficeRenditionSettings = new static();
        $driveOfficeRenditionSettings->enabled = (bool)($renditionsConfig['enabled'] ?? false);
        $driveOfficeRenditionSettings->maxSourceBytes = max(0, (int)($renditionsConfig['maxSourceBytes'] ?? self::DEFAULT_MAX_SOURCE_BYTES));
        $driveOfficeRenditionSettings->timeoutSeconds = max(self::MIN_TIMEOUT_SECONDS, (int)($renditionsConfig['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS));
        $driveOfficeRenditionSettings->maxPages = max(1, (int)($renditionsConfig['maxPages'] ?? self::DEFAULT_MAX_PAGES));
        $binary = trim((string)($renditionsConfig['binary'] ?? ''));
        $driveOfficeRenditionSettings->binary = $binary !== '' ? $binary : self::DEFAULT_BINARY;
        $commandPrefix = $renditionsConfig['commandPrefix'] ?? [];
        $driveOfficeRenditionSettings->commandPrefix = is_array($commandPrefix)
            ? array_values(array_filter(array_map(static fn(mixed $word): string => trim((string)$word), $commandPrefix), static fn(string $word): bool => $word !== ''))
            : [];
        if (array_key_exists('addressSpaceBytes', $renditionsConfig)) {
            $addressSpaceBytes = $renditionsConfig['addressSpaceBytes'];
            $driveOfficeRenditionSettings->addressSpaceBytes = $addressSpaceBytes !== null && (int)$addressSpaceBytes > 0 ? (int)$addressSpaceBytes : null;
        }
        return $driveOfficeRenditionSettings;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic('officeRenditions');
    }
}
