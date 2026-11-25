<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckInterface;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionHandler;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionMessage;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Installation check `driveRenditions` of `app:agent:check-installation` (ddd-agents >= 0.5.0): the Office renditions
 * are switched on (`CloudDrive.settings.officeRenditions.enabled`), but `DriveOfficeRenditionMessage` is routed to no
 * transport a worker consumes ({@see DriveOfficeRenditionsService::findRenditionRoutingProblem()}).
 *
 * BLOCKING where this environment starts conversions: the rendition route of a web request then answers FAILED
 * (`NOT_ROUTED_TO_WORKER`) for every file without a stored PDF. WARNING where it starts none (the host's switch for
 * derived objects answers false): the route serves only stored PDFs there anyway.
 *
 * Registered only beside ddd-agents 0.5.0 or later (`CloudDriveModule::getNamespacesRequiringClasses()`).
 */
class DriveRenditionsInstallationCheckService extends Service implements AgentInstallationCheckInterface
{
    public const string CHECK_NAME = 'driveRenditions';

    /** @var string The subject of the finding: the settings key of the renditions */
    public const string FINDING_SUBJECT = 'CloudDrive.settings.officeRenditions';

    public function getInstallationCheckName(): string
    {
        return static::CHECK_NAME;
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $driveOfficeRenditionsService = $this->getDriveOfficeRenditionsService();
        $renditionRoutingProblem = $driveOfficeRenditionsService->findRenditionRoutingProblem();
        if ($renditionRoutingProblem === null) {
            return $agentInstallationFindings;
        }
        $isConversionStartedHere = $this->isConversionStartedHere($driveOfficeRenditionsService);
        $agentInstallationFindings->addFinding(
            static::CHECK_NAME,
            $isConversionStartedHere ? AgentInstallationFinding::SEVERITY_BLOCKING : AgentInstallationFinding::SEVERITY_WARNING,
            static::FINDING_SUBJECT,
            $isConversionStartedHere
                ? $renditionRoutingProblem
                : $renditionRoutingProblem . ' This environment starts no conversion (DriveBlobsService::isDerivedObjectWriteAllowed() answers false), so only stored PDFs are served here.',
            sprintf(
                'Route %s to the transport "%s" and add its consumer to the worker list, or set CloudDrive.settings.officeRenditions.enabled to false.',
                DriveOfficeRenditionMessage::class,
                DriveOfficeRenditionHandler::TRANSPORT
            )
        );
        return $agentInstallationFindings;
    }

    /**
     * Whether this environment starts conversions: what the rendition route asks
     * ({@see DriveOfficeRenditionsService::isConversionStartAllowed()}, deprecated, whose default answers
     * {@see \DDD\Domain\CloudDrive\Services\DriveBlobsService::isDerivedObjectWriteAllowed()}), so a host's override of
     * either decides here too.
     */
    protected function isConversionStartedHere(DriveOfficeRenditionsService $driveOfficeRenditionsService): bool
    {
        return $driveOfficeRenditionsService->isConversionStartAllowed();
    }

    protected function getDriveOfficeRenditionsService(): DriveOfficeRenditionsService
    {
        /** @var DriveOfficeRenditionsService $driveOfficeRenditionsService */
        $driveOfficeRenditionsService = DDDService::instance()->getService(DriveOfficeRenditionsService::class);
        return $driveOfficeRenditionsService;
    }
}
