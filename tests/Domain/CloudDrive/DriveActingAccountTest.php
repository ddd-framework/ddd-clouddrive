<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\DDDBundle;
use DDD\Domain\CloudDrive\Services\DriveActivityEventsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ImpersonatingDriveChangesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Who acted and for whom comes from one pair of hooks on DriveChangesService: every service of the drive asks them, so
 * a host with impersonation overrides two methods and every write path follows.
 */
class DriveActingAccountTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->replaceDriveChangesService(null);
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        parent::tearDown();
    }

    public function testTheDefaultActorIsTheSignedInAccountActingForItself(): void
    {
        $account = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $account->id = 42;
        AuthService::instance()->setAccount($account);
        $driveChangesService = new DriveChangesService();
        self::assertSame(42, $driveChangesService->findActingAccountIdOfCurrentCaller());
        self::assertNull($driveChangesService->findOnBehalfOfAccountIdOfCurrentCaller());
    }

    public function testEveryServiceAsksTheOneHookPair(): void
    {
        $this->replaceDriveChangesService(new ImpersonatingDriveChangesService(7, 777));
        foreach ([DriveNodesService::class, DriveWorkingCopiesService::class, DriveActivityEventsService::class] as $serviceClass) {
            $service = new $serviceClass();
            self::assertSame(7, (new ReflectionMethod($service, 'findActingAccountId'))->invoke($service), $serviceClass . ' acting account');
            self::assertSame(777, (new ReflectionMethod($service, 'findOnBehalfOfAccountId'))->invoke($service), $serviceClass . ' account acted for');
        }
    }

    /** Puts a service into the suite's container (null = back to the class the container builds itself) */
    protected function replaceDriveChangesService(?DriveChangesService $driveChangesService): void
    {
        $container = (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
        $testInstancesProperty = new ReflectionProperty($container, 'testInstances');
        $testInstances = $testInstancesProperty->getValue($container);
        if ($driveChangesService === null) {
            unset($testInstances[DriveChangesService::class]);
        } else {
            $testInstances[DriveChangesService::class] = $driveChangesService;
        }
        $testInstancesProperty->setValue($container, $testInstances);
    }
}
