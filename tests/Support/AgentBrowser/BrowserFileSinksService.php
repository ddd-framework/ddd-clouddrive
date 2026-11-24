<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser;

use DDD\Infrastructure\Untrusted\RasterImageDecoder;

/** Test stand-in for the file sinks service of ddd-agent-browser ≥ 0.2.0 (its type detection and web folder) */
class BrowserFileSinksService
{
    public function detectContentType(string $bytes, ?string $claimedMimeType = null): string
    {
        $rasterMimeType = RasterImageDecoder::detectMimeType($bytes);
        if ($rasterMimeType !== null) {
            return $rasterMimeType;
        }
        $head = substr($bytes, 0, 4);
        if ($head === 'wOF2') {
            return 'font/woff2';
        }
        $textHead = ltrim(substr($bytes, 0, 2048));
        if (preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE svg[^>]*>\s*)?<svg[\s>]/is', $textHead) === 1) {
            return 'image/svg+xml';
        }
        if (str_starts_with($textHead, '%PDF')) {
            return 'application/pdf';
        }
        if (stripos($textHead, '<html') !== false) {
            return 'text/html';
        }
        return $claimedMimeType === 'text/css' ? 'text/css' : 'text/plain';
    }

    public function buildWebFolder(?string $pageUrl): string
    {
        $host = strtolower((string)parse_url((string)$pageUrl, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $host = trim((string)preg_replace('/[^a-z0-9.-]+/', '-', $host), '.-');
        return 'web/' . ($host !== '' ? $host : 'unknown-site');
    }
}
