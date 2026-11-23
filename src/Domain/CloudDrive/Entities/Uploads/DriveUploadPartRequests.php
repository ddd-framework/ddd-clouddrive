<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Uploads;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DriveUploadPartRequest[] $elements
 * @method DriveUploadPartRequest[] getElements()
 * @method DriveUploadPartRequest|null first()
 */
class DriveUploadPartRequests extends ObjectSet
{
}
