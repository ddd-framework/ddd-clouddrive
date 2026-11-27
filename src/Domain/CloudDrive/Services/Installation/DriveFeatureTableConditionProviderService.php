<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent;
use DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces\AgentSpaceDriveSpace;
use DDD\Domain\CloudDrive\Entities\Blobs\DriveBlob;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalItem;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalRendition;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalSpace;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopyEntry;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Modules\CloudDrive\CloudDriveModule;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The tables of the drive for `app:agent:check-installation` of ddd-agents (>= 0.5.0): they are required only while
 * the drive is enabled, i.e. its object store (`CloudDrive.settings.objectStorageName`, default `clouddrive`) is
 * configured — without it the drive stores and reads no file. A host with the drive installed but not set up may leave
 * them out. Registered only beside ddd-agents 0.5.0 or later (`CloudDriveModule::getNamespacesRequiringClasses()`).
 */
class DriveFeatureTableConditionProviderService extends Service implements AgentFeatureTableConditionProvider
{
    /** @var string What reads the tables, for the reason of the check */
    public const string DRIVE_FEATURE_DESCRIPTION = 'the cloud drive (its object store CloudDrive.settings.objectStorageName, OBJECT_STORAGE_<NAME>_*)';

    /** @var string[] The entities whose tables only the enabled drive reads: every database entity of the package but the external drive ones */
    public const array DRIVE_TABLE_ENTITY_CLASSES = [
        DriveActivityEvent::class,
        AgentSpaceDriveSpace::class,
        DriveBlob::class,
        DriveChangeEvent::class,
        DriveNode::class,
        DriveSpace::class,
        DriveFileVersion::class,
        DriveWorkingCopy::class,
        DriveWorkingCopyEntry::class,
    ];

    /** @var string What reads the external drive tables, for the reason of the check */
    public const string EXTERNAL_DRIVE_FEATURE_DESCRIPTION = 'external drives (the cloud drive and AgentConnections of ddd-agents, AI.agentConnections.enabled)';

    /** @var string[] The tables only external drives read: required while the drive AND AgentConnections are enabled */
    public const array EXTERNAL_DRIVE_TABLE_ENTITY_CLASSES = [
        DriveExternalItem::class,
        DriveExternalRendition::class,
        DriveExternalSpace::class,
    ];

    public function getFeatureTableConditions(): AgentFeatureTableConditions
    {
        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        $isDriveEnabled = $this->isDriveEnabled();
        foreach (static::DRIVE_TABLE_ENTITY_CLASSES as $entityClassName) {
            $driveTableCondition = AgentFeatureTableCondition::create($entityClassName, static::DRIVE_FEATURE_DESCRIPTION, $isDriveEnabled);
            $agentFeatureTableConditions->add($driveTableCondition);
        }
        $areExternalDrivesEnabled = $isDriveEnabled && $this->areAgentConnectionsEnabled();
        foreach (static::EXTERNAL_DRIVE_TABLE_ENTITY_CLASSES as $entityClassName) {
            $externalDriveTableCondition = AgentFeatureTableCondition::create($entityClassName, static::EXTERNAL_DRIVE_FEATURE_DESCRIPTION, $areExternalDrivesEnabled);
            $agentFeatureTableConditions->add($externalDriveTableCondition);
        }
        return $agentFeatureTableConditions;
    }

    /** AgentConnections of ddd-agents is installed and switched on */
    protected function areAgentConnectionsEnabled(): bool
    {
        return class_exists(CloudDriveModule::AGENT_CONNECTIONS_CLASS) && AgentConnectionsConfiguration::current()->isEnabled();
    }

    /**
     * Whether the drive is enabled: its object store is configured. A store whose configuration throws (an unknown
     * driver, an incomplete store) counts as enabled, so the tables stay required and the setup error surfaces.
     */
    protected function isDriveEnabled(): bool
    {
        /** @var DriveBlobsService $driveBlobsService */
        $driveBlobsService = DDDService::instance()->getService(DriveBlobsService::class);
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        try {
            return $objectStorageService->isObjectStorageConfigured($driveBlobsService->getObjectStorageName());
        } catch (Throwable) {
            return true;
        }
    }
}
