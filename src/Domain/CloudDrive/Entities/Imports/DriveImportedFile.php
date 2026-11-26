<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Imports;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;

/**
 * One file an import wrote into the drive.
 */
class DriveImportedFile extends ValueObject
{
    /** @var FileReference Where the file came from */
    public FileReference $sourceFileReference;

    /** @var DriveNode The file in the drive */
    public DriveNode $driveNode;

    /** @var string The file's path in its space */
    public string $path = '';

    /** @var bool True when the folder already held these bytes under this name: nothing was written */
    public bool $isUnchanged = false;

    /** @var bool True when the copy was written without the picture's metadata */
    public bool $isMetadataRemoved = false;

    /** @var string The state of the written content when the import returned: one of DriveBlob::STATE_* */
    public string $contentState = '';

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->sourceFileReference->toString() . '>' . ($this->driveNode->id ?? 0));
    }
}
