<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Runs the ingest of one version ({@see DriveVersionsService::ingestVersion()}); a lost run is redone by
 * `app:drive:maintain`. The ingest is a system job: it runs without rights restrictions, so a message without an
 * account (dispatched by a command, a migration, an agent run without a person) is ingested like any other. A version
 * that does not exist (any more) is logged, never dropped silently.
 */
#[AsMessageHandler(fromTransport: DriveIngestVersionHandler::TRANSPORT)]
class DriveIngestVersionHandler extends AppMessageHandler
{
    public const string TRANSPORT = 'drive_ingest';

    public function __invoke(DriveIngestVersionMessage $message): void
    {
        set_time_limit(300);
        DDDService::instance()->raiseMemoryLimitToAtLeast('512M');
        $this->setAuthAccountFromMessage($message);
        $this->getLogger()->info("Ingest of drive version {$message->driveFileVersionId}");
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var DriveVersionsService $driveVersionsService */
            $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
            $driveFileVersion = $driveVersionsService->find((int)$message->driveFileVersionId, false);
            if (!$driveFileVersion instanceof DriveFileVersion) {
                $this->getLogger()->warning("Ingest of drive version {$message->driveFileVersionId} skipped: the version does not exist");
                return;
            }
            $driveVersionsService->ingestVersion($driveFileVersion, $message->isScanned, $message->workingCopyKey, async: false);
        } catch (Throwable $throwable) {
            $this->logShortException($this->getLogger(), "Ingest of drive version {$message->driveFileVersionId}", $throwable);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
