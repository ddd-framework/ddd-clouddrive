<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser;

use DDD\Domain\AI\Entities\Browser\Files\BrowserFileSinkContext;
use DDD\Domain\AI\Entities\Browser\Files\BrowserFileToStore;
use DDD\Domain\AI\Entities\Browser\Files\BrowserStoredFile;

/** Test stand-in for the file sink port of ddd-agent-browser ≥ 0.2.0, loaded only when the package is not installed */
interface BrowserFileSink
{
    public const string TAG = 'browser.file_sink';

    public function storeFile(BrowserFileToStore $browserFileToStore, BrowserFileSinkContext $browserFileSinkContext): BrowserStoredFile;
}
