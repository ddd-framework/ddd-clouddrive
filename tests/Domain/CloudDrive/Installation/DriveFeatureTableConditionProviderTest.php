<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Installation;

use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\Installation\DriveFeatureTableConditionProviderService;
use DDD\Domain\CloudDrive\Services\Installation\DriveRenditionsInstallationCheckService;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Modules\CloudDrive\CloudDriveModule;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ModuleWithoutAgentInstallationPort;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\SwitchableDriveFeatureTableConditionProviderService;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** The drive tables are required by app:agent:check-installation of ddd-agents only while the drive is enabled */
class DriveFeatureTableConditionProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        $objectStorageService->setObjectStorage('clouddrive', null);
    }

    public function testTheTablesAreNotRequiredWhileTheDriveIsNotEnabled(): void
    {
        $driveFeatureTableConditionProviderService = new SwitchableDriveFeatureTableConditionProviderService();
        $agentFeatureTableConditions = $driveFeatureTableConditionProviderService->getFeatureTableConditions();

        foreach (DriveFeatureTableConditionProviderService::DRIVE_TABLE_ENTITY_CLASSES as $entityClassName) {
            $reason = $agentFeatureTableConditions->findReasonTableIsNotRequired($entityClassName, []);
            self::assertNotNull($reason, $entityClassName);
            self::assertStringContainsString('switched off', $reason);
        }
    }

    public function testTheTablesAreRequiredWhileTheDriveIsEnabled(): void
    {
        $driveFeatureTableConditionProviderService = new SwitchableDriveFeatureTableConditionProviderService();
        $driveFeatureTableConditionProviderService->driveEnabled = true;
        $agentFeatureTableConditions = $driveFeatureTableConditionProviderService->getFeatureTableConditions();

        foreach (DriveFeatureTableConditionProviderService::DRIVE_TABLE_ENTITY_CLASSES as $entityClassName) {
            self::assertNull($agentFeatureTableConditions->findReasonTableIsNotRequired($entityClassName, []), $entityClassName);
        }
    }

    public function testTheExternalDriveTablesAreRequiredOnlyWhileTheDriveAndAgentConnectionsAreEnabled(): void
    {
        $driveFeatureTableConditionProviderService = new SwitchableDriveFeatureTableConditionProviderService();
        foreach ([[false, false], [true, false], [false, true]] as [$driveEnabled, $agentConnectionsEnabled]) {
            $driveFeatureTableConditionProviderService->driveEnabled = $driveEnabled;
            $driveFeatureTableConditionProviderService->agentConnectionsEnabled = $agentConnectionsEnabled;
            $agentFeatureTableConditions = $driveFeatureTableConditionProviderService->getFeatureTableConditions();
            foreach (DriveFeatureTableConditionProviderService::EXTERNAL_DRIVE_TABLE_ENTITY_CLASSES as $entityClassName) {
                self::assertNotNull($agentFeatureTableConditions->findReasonTableIsNotRequired($entityClassName, []), $entityClassName);
            }
        }
        $driveFeatureTableConditionProviderService->driveEnabled = true;
        $driveFeatureTableConditionProviderService->agentConnectionsEnabled = true;
        $agentFeatureTableConditions = $driveFeatureTableConditionProviderService->getFeatureTableConditions();
        foreach (DriveFeatureTableConditionProviderService::EXTERNAL_DRIVE_TABLE_ENTITY_CLASSES as $entityClassName) {
            self::assertNull($agentFeatureTableConditions->findReasonTableIsNotRequired($entityClassName, []), $entityClassName);
        }
    }

    public function testTheDriveIsEnabledWhenItsObjectStoreIsConfigured(): void
    {
        $driveFeatureTableConditionProviderService = new DriveFeatureTableConditionProviderService();
        self::assertNotNull($driveFeatureTableConditionProviderService->getFeatureTableConditions()->findReasonTableIsNotRequired(DriveNode::class, []));

        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        $objectStorageService->setObjectStorage('clouddrive', new FilesystemObjectStorage(sys_get_temp_dir() . '/clouddrive-table-condition-' . bin2hex(random_bytes(4))));

        self::assertNull($driveFeatureTableConditionProviderService->getFeatureTableConditions()->findReasonTableIsNotRequired(DriveNode::class, []));
    }

    public function testEveryDatabaseEntityOfThePackageHasACondition(): void
    {
        $sourceDirectory = dirname(__DIR__, 4) . '/src/Domain/CloudDrive/Entities';
        $databaseEntityClassNames = [];
        $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($directoryIterator as $fileInfo) {
            $source = (string)file_get_contents($fileInfo->getPathname());
            if (str_contains($source, 'LazyLoadRepo::DB') && preg_match('/^namespace (.+);$/m', $source, $namespaceMatch) === 1
                && !str_ends_with($fileInfo->getBasename('.php'), 's')) {
                $databaseEntityClassNames[] = $namespaceMatch[1] . '\\' . $fileInfo->getBasename('.php');
            }
        }
        $declaredEntityClassNames = [...DriveFeatureTableConditionProviderService::DRIVE_TABLE_ENTITY_CLASSES, ...DriveFeatureTableConditionProviderService::EXTERNAL_DRIVE_TABLE_ENTITY_CLASSES];
        sort($databaseEntityClassNames);
        sort($declaredEntityClassNames);

        self::assertSame($databaseEntityClassNames, $declaredEntityClassNames);
    }

    public function testTheInstallationPartRegistersOnlyBesideTheAgentPackagePort(): void
    {
        self::assertInstanceOf(AgentFeatureTableConditionProvider::class, new SwitchableDriveFeatureTableConditionProviderService());
        self::assertSame(
            AgentFeatureTableConditionProvider::class,
            CloudDriveModule::getNamespacesRequiringClasses()['DDD\\Domain\\CloudDrive\\Services\\Installation\\']
        );
        // beside ddd-agents (the suite installs it): both services register
        self::assertFalse(CloudDriveModule::isClassOfAnInactivePart(DriveFeatureTableConditionProviderService::class));
        self::assertFalse(CloudDriveModule::isClassOfAnInactivePart(DriveRenditionsInstallationCheckService::class));
        // without the port (a host without ddd-agents 0.5): neither is a service, their files are never loaded
        self::assertTrue(ModuleWithoutAgentInstallationPort::isClassOfAnInactivePart(DriveFeatureTableConditionProviderService::class));
        self::assertTrue(ModuleWithoutAgentInstallationPort::isClassOfAnInactivePart(DriveRenditionsInstallationCheckService::class));
        self::assertFalse(ModuleWithoutAgentInstallationPort::isClassOfAnInactivePart(DriveBlobsService::class), 'the drive itself stays');
    }
}
