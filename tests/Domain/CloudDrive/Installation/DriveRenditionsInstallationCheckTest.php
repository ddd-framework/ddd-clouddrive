<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckInterface;
use DDD\Domain\CloudDrive\MessageHandlers\DriveOfficeRenditionMessage;
use DDD\Domain\CloudDrive\Services\Installation\DriveRenditionsInstallationCheckService;
use DDD\Domain\Common\Services\WorkerTransportsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\DriveOfficeRenditionTestCase;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RoutingTestDriveRenditionsInstallationCheckService;

/** The check `driveRenditions` of app:agent:check-installation: renditions switched on but routed to no worker */
class DriveRenditionsInstallationCheckTest extends DriveOfficeRenditionTestCase
{
    protected RoutingTestDriveRenditionsInstallationCheckService $driveRenditionsInstallationCheckService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveRenditionsInstallationCheckService = new RoutingTestDriveRenditionsInstallationCheckService($this->driveOfficeRenditionsService);
    }

    protected function routeRenditionMessageTo(string $transportName, string $dsn): void
    {
        $this->driveOfficeRenditionsService->testWorkerTransportsService = new WorkerTransportsService(
            receiverNames: [$transportName],
            transportDsnsByName: [$transportName => $dsn],
            transportNamesByMessageClass: [DriveOfficeRenditionMessage::class => $transportName]
        );
    }

    public function testNoFindingWhileRenditionsAreSwitchedOff(): void
    {
        $this->driveOfficeRenditionsService->testSettings->enabled = false;

        self::assertSame(0, $this->driveRenditionsInstallationCheckService->findInstallationFindings()->count());
    }

    public function testNoFindingWhileTheMessageIsRoutedToAWorker(): void
    {
        $this->routeRenditionMessageTo('drive_render', 'amqp://broker/%2f/drive_render');

        self::assertSame(0, $this->driveRenditionsInstallationCheckService->findInstallationFindings()->count());
    }

    public function testRenditionsSwitchedOnAndRoutedNowhereAreBlocking(): void
    {
        $agentInstallationFindings = $this->driveRenditionsInstallationCheckService->findInstallationFindings();

        self::assertSame(1, $agentInstallationFindings->count());
        $agentInstallationFinding = $agentInstallationFindings->getElements()[0];
        self::assertSame(DriveRenditionsInstallationCheckService::CHECK_NAME, $agentInstallationFinding->checkName);
        self::assertSame(AgentInstallationFinding::SEVERITY_BLOCKING, $agentInstallationFinding->severity);
        self::assertSame($this->driveOfficeRenditionsService->findRenditionRoutingProblem(), $agentInstallationFinding->message);
        self::assertStringContainsString('drive_render', (string)$agentInstallationFinding->hint);
        self::assertTrue($agentInstallationFindings->hasBlockingFindings());
    }

    public function testASynchronousTransportIsBlockingToo(): void
    {
        $this->routeRenditionMessageTo('drive_render', 'sync://');

        $agentInstallationFindings = $this->driveRenditionsInstallationCheckService->findInstallationFindings();
        self::assertSame(1, $agentInstallationFindings->count());
        self::assertSame(AgentInstallationFinding::SEVERITY_BLOCKING, $agentInstallationFindings->getElements()[0]->severity);
    }

    public function testAnEnvironmentThatStartsNoConversionGetsAWarning(): void
    {
        // a host's override of the switch: reads of this environment derive nothing, renditions do not fail more here
        $this->driveOfficeRenditionsService->isConversionStartAllowedOverride = false;

        $agentInstallationFindings = $this->driveRenditionsInstallationCheckService->findInstallationFindings();
        self::assertSame(1, $agentInstallationFindings->count());
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $agentInstallationFindings->getElements()[0]->severity);
        self::assertFalse($agentInstallationFindings->hasBlockingFindings());
    }

    public function testTheCheckIsAnInstallationCheckOfTheAgentPackage(): void
    {
        self::assertInstanceOf(AgentInstallationCheckInterface::class, $this->driveRenditionsInstallationCheckService);
        self::assertSame('driveRenditions', $this->driveRenditionsInstallationCheckService->getInstallationCheckName());
    }
}
