<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive;

use DDD\Testing\UntrustedInput\UntrustedSinkScanTestCase;

/**
 * Every raw sink call of the module goes through a helper of DDD\Infrastructure\Untrusted or is listed in
 * config/untrusted-sinks-allowlist.php.
 */
class UntrustedSinkScanTest extends UntrustedSinkScanTestCase
{
    protected function getPackageRootDirectory(): string
    {
        return dirname(__DIR__);
    }
}
