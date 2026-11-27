<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\External;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Libs\Connections\AgentConnectionDriveReach;
use DDD\Domain\AI\Services\Connections\Adapters\Fake\FakeAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccess;
use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalFolderNames;
use DDD\Domain\CloudDrive\Entities\External\DriveExternalItem;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\AI\External\ExternalDriveAgentStoreService;
use DDD\Domain\CloudDrive\Services\AI\External\ExternalDriveContentService;
use DDD\Domain\CloudDrive\Services\AI\External\ExternalDriveSpacesService;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Modules\CloudDrive\CloudDriveModule;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\NamingExternalDriveContentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * External drives: a space of kind externalDrive bound by its own row, never reached through the drive's own rights,
 * nothing while AgentConnections is off, read-only filesystem names that stay unique per folder.
 */
class ExternalDriveSpacesTest extends TestCase
{
    public function testAnExternalSpaceIsMarkedByItsKindAlone(): void
    {
        self::assertFalse($this->buildSpace(4, DriveSpace::DEFAULT_SPACE_KIND)->isExternal());
        self::assertTrue($this->buildSpace(5, DriveSpace::EXTERNAL_SPACE_KIND)->isExternal());
    }

    public function testNothingIsReachedWhileAgentConnectionsIsOff(): void
    {
        $externalDriveSpacesService = $this->getSpacesService();
        self::assertSame(0, $externalDriveSpacesService->findReachableSpacesOfAccount(7, AgentConnectionReach::inSpace(13))->count());

        $this->expectException(ForbiddenException::class);
        $externalDriveSpacesService->requireReachOfSpace($this->buildSpace(5, DriveSpace::EXTERNAL_SPACE_KIND), 7, $this->buildCallProvenance(13, 501));
    }

    public function testASpaceOfOurOwnHasNoProviderRoot(): void
    {
        self::assertNull($this->getSpacesService()->findRootItemIdOfSpace($this->buildSpace(4, DriveSpace::DEFAULT_SPACE_KIND)));
    }

    public function testTheDrivesOwnRightsLeaveExternalSpacesOut(): void
    {
        $queryBuilder = new DoctrineQueryBuilder($this->createMock(EntityManagerInterface::class));
        DBDriveSpace::applyExternalSpaceExclusion($queryBuilder, 'space');
        self::assertSame('space.spaceKind <> :space_externalSpaceKind', (string)$queryBuilder->getDQLPart('where'));
        self::assertSame(DriveSpace::EXTERNAL_SPACE_KIND, $queryBuilder->getParameter('space_externalSpaceKind')?->getValue());
    }

    public function testFilesystemNamesStayUniqueInAFolderAndNativeDocumentsGetTheirViewSuffix(): void
    {
        $namingContentService = new NamingExternalDriveContentService();
        $folderNames = DriveExternalFolderNames::newInstance();
        $firstReport = $this->buildDriveItem('a1', 'Report.pdf', AgentConnectionDriveItem::KIND_FILE);
        $secondReport = $this->buildDriveItem('b2', 'report.pdf', AgentConnectionDriveItem::KIND_FILE);
        $plan = $this->buildDriveItem('c3', 'Plan', AgentConnectionDriveItem::KIND_GOOGLE_DOC);

        self::assertSame('Report.pdf', $namingContentService->nameInFolder($firstReport, $folderNames));
        $clashingName = $namingContentService->nameInFolder($secondReport, $folderNames);
        self::assertMatchesRegularExpression('/^report \([0-9a-f]{6}\)\.pdf$/', $clashingName, 'a case-insensitive clash gets a short id before the extension');
        self::assertSame('Plan' . ExternalDriveContentService::VIEW_SUFFIX_BY_KIND[AgentConnectionDriveItem::KIND_GOOGLE_DOC], $namingContentService->nameInFolder($plan, $folderNames));
        self::assertTrue($folderNames->isTaken('REPORT.PDF'));
        self::assertSame('unnamed', $namingContentService->nameInFolder($this->buildDriveItem('d4', '..', AgentConnectionDriveItem::KIND_FILE), DriveExternalFolderNames::newInstance()));
    }

    public function testAMirrorItemReadsItsKindFromTheConstants(): void
    {
        $driveExternalItem = DriveExternalItem::newInstance();
        $driveExternalItem->kind = AgentConnectionDriveItem::KIND_FOLDER;
        self::assertTrue($driveExternalItem->isFolder());
        $driveExternalItem->kind = AgentConnectionDriveItem::KIND_GOOGLE_SHEET;
        self::assertTrue($driveExternalItem->isNativeDocument());
        self::assertFalse($driveExternalItem->isFolder());
    }

    public function testTheExternalPartRegistersOnlyBesideAgentConnections(): void
    {
        $namespacesRequiringClasses = CloudDriveModule::getNamespacesRequiringClasses();
        foreach (['Services\\AI\\External\\', 'Services\\External\\', 'Entities\\External\\', 'Repo\\DB\\External\\'] as $namespaceSuffix) {
            self::assertSame(CloudDriveModule::AGENT_CONNECTIONS_CLASS, $namespacesRequiringClasses['DDD\\Domain\\CloudDrive\\' . $namespaceSuffix] ?? null, $namespaceSuffix);
        }
        self::assertTrue(ExternalDriveAgentStoreService::isExternalMount(null) === false);
    }

    public function testAProviderReadCarriesTheSpaceAndConversationOfItsCall(): void
    {
        $agentConnection = AgentConnection::newInstance();
        $agentConnection->id = 3;
        $driveReach = new AgentConnectionDriveReach($agentConnection, AgentConnectionAccess::asOwner(), $this->createMock(FakeAgentConnectionAdapter::class), $this->createMock(FakeAgentConnectionAdapter::class), $this->buildCallProvenance(13, 501));
        $agentConnectionOperation = $this->getContentService()->buildReadOperation($driveReach, 7, AgentConnectionAction::OPERATION_READ, 'drive.materialize');

        self::assertSame(501, $agentConnectionOperation->getConversationId(), 'the audience rule sees the conversation');
        self::assertSame(13, $agentConnectionOperation->getReach()->agentSpaceId, 'a member reaches the release to the call\'s space');
        self::assertSame(501, $agentConnectionOperation->getReach()->aIConversationId, 'the owner\'s own access is weighed against the conversation\'s readers');
    }

    public function testACallReachNamesItsSpaceAndConversation(): void
    {
        $agentConnectionReach = AgentConnectionReach::ofCall($this->buildCallProvenance(13, 501));
        self::assertSame(13, $agentConnectionReach->agentSpaceId);
        self::assertSame(501, $agentConnectionReach->aIConversationId);
        self::assertFalse($agentConnectionReach->acrossMemberSpaces);
        self::assertNull(AgentConnectionReach::ofCall(null)->aIConversationId);
    }

    protected function buildCallProvenance(int $agentSpaceId, int $conversationId): AgentToolCallProvenance
    {
        $callProvenance = new AgentToolCallProvenance(conversationId: $conversationId);
        $callProvenance->agentSpaceId = $agentSpaceId;
        return $callProvenance;
    }

    protected function getContentService(): ExternalDriveContentService
    {
        /** @var ExternalDriveContentService $externalDriveContentService */
        $externalDriveContentService = DDDService::instance()->getService(ExternalDriveContentService::class);
        return $externalDriveContentService;
    }

    protected function buildSpace(int $driveSpaceId, string $spaceKind): DriveSpace
    {
        /** @var DriveSpace $driveSpace */
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->id = $driveSpaceId;
        $driveSpace->spaceKind = $spaceKind;
        return $driveSpace;
    }

    protected function buildDriveItem(string $itemId, string $name, string $kind): AgentConnectionDriveItem
    {
        $driveItem = AgentConnectionDriveItem::newInstance();
        $driveItem->itemId = $itemId;
        $driveItem->name = $name;
        $driveItem->kind = $kind;
        return $driveItem;
    }

    protected function getSpacesService(): ExternalDriveSpacesService
    {
        /** @var ExternalDriveSpacesService $externalDriveSpacesService */
        $externalDriveSpacesService = DDDService::instance()->getService(ExternalDriveSpacesService::class);
        return $externalDriveSpacesService;
    }
}
