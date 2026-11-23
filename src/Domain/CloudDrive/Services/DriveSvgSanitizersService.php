<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/** The host's SVG sanitiser ({@see DriveSvgSanitizer}), the first one registered; none = SVG stays attachment only */
class DriveSvgSanitizersService extends Service
{
    /** @var int A larger SVG is never sanitised (and never shown inline) */
    public const int MAX_SVG_BYTES = 2 * 1024 * 1024;

    /** @var iterable<DriveSvgSanitizer> */
    protected iterable $driveSvgSanitizers = [];

    /** @param iterable<DriveSvgSanitizer> $driveSvgSanitizers */
    #[Required]
    public function setDriveSvgSanitizers(
        #[AutowireIterator(DriveSvgSanitizer::TAG)]
        iterable $driveSvgSanitizers
    ): void {
        $this->driveSvgSanitizers = $driveSvgSanitizers;
    }

    public function hasSvgSanitizer(): bool
    {
        foreach ($this->driveSvgSanitizers as $driveSvgSanitizer) {
            return true;
        }
        return false;
    }

    /** The sanitised SVG; null without a sanitiser, for a too large SVG, or when the sanitiser refused or failed */
    public function sanitizeSvg(string $svgBytes): ?string
    {
        if (strlen($svgBytes) > static::MAX_SVG_BYTES) {
            return null;
        }
        foreach ($this->driveSvgSanitizers as $driveSvgSanitizer) {
            try {
                $sanitizedSvg = $driveSvgSanitizer->sanitizeSvg($svgBytes);
            } catch (Throwable $throwable) {
                DDDService::instance()->getLogger()->warning('[CloudDrive] SVG sanitiser failed', [
                    'sanitizer' => $driveSvgSanitizer::class,
                    'reason' => $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 500),
                ]);
                return null;
            }
            return is_string($sanitizedSvg) && $sanitizedSvg !== '' ? $sanitizedSvg : null;
        }
        return null;
    }
}
