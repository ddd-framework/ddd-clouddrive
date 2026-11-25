<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * The conversion of one version's Office content to its PDF rendition in a worker
 * ({@see \DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService}, setting `CloudDrive.settings.officeRenditions`).
 * The host routes it to the transport `drive_render` and gives that transport a consumer whose image holds LibreOffice
 * and fonts. A web request dispatches it only while it is routed to a transport a worker consumes
 * ({@see \DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService::isRenditionMessageRoutedToWorker()}); routed
 * nowhere, only a console command handles it in-process.
 */
class DriveOfficeRenditionMessage extends AppMessage
{
    public static string $messageHandler = DriveOfficeRenditionHandler::class;

    /** @var int|null The version whose content is converted (the rendition is stored per content, not per version) */
    public ?int $driveFileVersionId = null;

    public function __construct(?int $driveFileVersionId = null)
    {
        parent::__construct();
        $this->driveFileVersionId = $driveFileVersionId;
    }
}
