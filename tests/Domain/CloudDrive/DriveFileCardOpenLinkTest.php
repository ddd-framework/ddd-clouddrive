<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\AI\Entities\Drives\AgentDriveFile;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\LinkTemplateDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\MountNamingDriveSpacesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\OpenLinkCloudDriveAgentDriveStoreService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The agent's file cards carry the host's deep link when a link template is set */
class DriveFileCardOpenLinkTest extends TestCase
{
    public function testTheCardCarriesTheDeepLinkOnlyWithATemplate(): void
    {
        if (!property_exists(AgentDriveFile::class, 'openLink')) {
            self::markTestSkipped('the installed ddd-agents predates AgentDriveFile::$openLink (0.1.33)');
        }
        $linkTemplateDriveNodesService = new LinkTemplateDriveNodesService(new MountNamingDriveSpacesService());
        $linkTemplateDriveNodesService->addNode(1, 12, null, '');
        $linkTemplateDriveNodesService->addNode(2, 12, 1, 'Templates');
        $fileNode = $linkTemplateDriveNodesService->addNode(3, 12, 2, 'letter v2.typ', DriveNode::NODE_TYPE_FILE);
        $openLinkCloudDriveAgentDriveStoreService = new OpenLinkCloudDriveAgentDriveStoreService($linkTemplateDriveNodesService);
        $applyOpenLinkMethod = new ReflectionMethod($openLinkCloudDriveAgentDriveStoreService, 'applyOpenLink');

        $cardWithoutTemplate = new AgentDriveFile();
        $applyOpenLinkMethod->invoke($openLinkCloudDriveAgentDriveStoreService, $cardWithoutTemplate, $fileNode);
        self::assertNull($cardWithoutTemplate->openLink);

        $linkTemplateDriveNodesService->adminLinkTemplate = '/admin/drive?space={spaceId}&path={path}&file={nodeId}';
        $cardWithTemplate = new AgentDriveFile();
        $applyOpenLinkMethod->invoke($openLinkCloudDriveAgentDriveStoreService, $cardWithTemplate, $fileNode);
        self::assertSame('/admin/drive?space=12&path=%2FTemplates%2Fletter%20v2.typ&file=3', $cardWithTemplate->openLink);

        $linkTemplateDriveNodesService->adminLinkTemplate = 'javascript:alert({nodeId})';
        $cardWithUnsafeTemplate = new AgentDriveFile();
        $applyOpenLinkMethod->invoke($openLinkCloudDriveAgentDriveStoreService, $cardWithUnsafeTemplate, $fileNode);
        self::assertNull($cardWithUnsafeTemplate->openLink);
    }
}
