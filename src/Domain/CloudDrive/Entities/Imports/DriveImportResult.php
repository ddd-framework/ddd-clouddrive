<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Imports;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Entities\Files\Sources\FileReferences;

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

    /** The files that were refused (the lines say why) */
    public FileReferences $refusedFileReferences;

    public function __construct()
    {
        $this->importedFiles = new DriveImportedFiles();
        $this->refusedFileReferences = new FileReferences();
        parent::__construct();
    }

    public function hasImportedFiles(): bool
    {
        return $this->importedFiles->count() > 0;
    }

    /** Adds a refused file with its line `<reference>: <reason>` */
    public function addRefusal(FileReference $fileReference, string $reason): void
    {
        $this->refusedLines[] = $fileReference->toString() . ': ' . $reason;
        $this->refusedFileReferences->add($fileReference);
    }
}
