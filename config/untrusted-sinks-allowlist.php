<?php

declare(strict_types=1);

/**
 * Raw sink calls of this module that are fine, each with its reason (Core skill ddd-untrusted-input-specialist).
 * Checked by tests/UntrustedSinkScanTest.php. The module has none: scans, merges and downloads go through the helpers
 * of DDD\Infrastructure\Untrusted (IsolatedProcess, RasterImageDecoder, UntrustedContentHeaders).
 */
return [];
