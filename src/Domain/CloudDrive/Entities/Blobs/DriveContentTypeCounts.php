<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Blobs;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveContentTypeCount[] $elements
 * @method DriveContentTypeCount[] getElements()
 * @method DriveContentTypeCount|null getByUniqueKey(string $uniqueKey)
 */
class DriveContentTypeCounts extends ObjectSet
{
    /**
     * Counts one more blob for `$contentType`.
     */
    public function countContentType(string $contentType): void
    {
        $driveContentTypeCount = $this->getByUniqueKey(DriveContentTypeCount::uniqueKeyStatic($contentType));
        if ($driveContentTypeCount === null) {
            $driveContentTypeCount = new DriveContentTypeCount();
            $driveContentTypeCount->contentType = $contentType;
            $this->add($driveContentTypeCount);
        }
        $driveContentTypeCount->blobCount++;
    }
}
