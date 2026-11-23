<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A host's SVG sanitiser (registered by this tag, autoconfigured). Without one, an SVG is only ever served as an
 * attachment (`application/octet-stream`). With one, an INLINE or THUMBNAIL grant of an SVG serves its sanitised copy
 * as `image/svg+xml` for an `<img>` (an image element runs no script; the sanitiser removes scripts, event handlers,
 * external references and foreign objects for the case the URL is opened directly). The copy is made once per blob
 * and stored next to it.
 */
#[AutoconfigureTag(DriveSvgSanitizer::TAG)]
interface DriveSvgSanitizer
{
    public const string TAG = 'clouddrive.svg_sanitizer';

    /**
     * @param string $svgBytes foreign bytes, at most {@see DriveSvgSanitizersService::MAX_SVG_BYTES}
     * @return string|null the sanitised SVG, null when it cannot be made safe (the SVG stays attachment only)
     */
    public function sanitizeSvg(string $svgBytes): ?string;
}
