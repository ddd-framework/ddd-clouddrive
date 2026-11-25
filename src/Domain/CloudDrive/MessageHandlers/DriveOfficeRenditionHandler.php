<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRenditionSettings;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Converts the content of one version to its PDF rendition ({@see DriveOfficeRenditionsService::renderRendition()}).
 * The host routes {@see DriveOfficeRenditionMessage} to the transport `drive_render`; its consumer needs LibreOffice
 * and fonts, and should have no network and little else (it opens files from outside). A system job like the ingest:
 * it runs without rights restrictions — the reader's rights were checked when the rendition was asked for and are
 * checked again when it is fetched. A lost run is dispatched again by the next request once its pending marker is
 * older than twice the timeout. A version that does not exist (any more) is logged, never dropped silently.
 */
#[AsMessageHandler(fromTransport: DriveOfficeRenditionHandler::TRANSPORT)]
class DriveOfficeRenditionHandler extends AppMessageHandler
{
    public const string TRANSPORT = 'drive_render';

    public function __invoke(DriveOfficeRenditionMessage $message): void
    {
        set_time_limit(DriveOfficeRenditionSettings::fromConfig()->timeoutSeconds + 120);
        // the PDF is read into memory once to be stored create-only (at most DriveOfficeRenditionsService::MAX_RENDITION_BYTES)
        DDDService::instance()->raiseMemoryLimitToAtLeast('768M');
        $this->setAuthAccountFromMessage($message);
        $this->getLogger()->info("PDF rendition of drive version {$message->driveFileVersionId}");
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var DriveVersionsService $driveVersionsService */
            $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
            $driveFileVersion = $driveVersionsService->find((int)$message->driveFileVersionId, false);
            if (!$driveFileVersion instanceof DriveFileVersion) {
                $this->getLogger()->warning("PDF rendition of drive version {$message->driveFileVersionId} skipped: the version does not exist");
                return;
            }
            /** @var DriveOfficeRenditionsService $driveOfficeRenditionsService */
            $driveOfficeRenditionsService = DDDService::instance()->getService(DriveOfficeRenditionsService::class);
            $driveOfficeRendition = $driveOfficeRenditionsService->renderRendition($driveFileVersion);
            $this->getLogger()->info("PDF rendition of drive version {$message->driveFileVersionId}: {$driveOfficeRendition->state}");
        } catch (Throwable $throwable) {
            $this->logShortException($this->getLogger(), "PDF rendition of drive version {$message->driveFileVersionId}", $throwable);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
