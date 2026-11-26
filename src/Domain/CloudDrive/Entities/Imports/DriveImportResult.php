<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Imports;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What an import did: the files it wrote and one line per file it refused. A refused file never stops the others.
 */
class DriveImportResult extends ValueObject
{
    public DriveImportedFiles $importedFiles;

    /** @var string[] `<reference>: <why not, and what would work>` per refused file */
    public array $refusedLines = [];

    /** @var bool True when at least one file was refused only because the owner has not consented yet */
    public bool $needsOwnerConsent = false;

    public function __construct()
    {
        $this->importedFiles = new DriveImportedFiles();
        parent::__construct();
    }

    public function hasImportedFiles(): bool
    {
        return $this->importedFiles->count() > 0;
    }
}
