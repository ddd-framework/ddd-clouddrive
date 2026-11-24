<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\AI;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Entities\AI\AgentSpaceDriveSpaces\AgentSpaceDriveSpace;
use DDD\Domain\CloudDrive\Entities\AI\Rights\CloudDriveAccountRights;
use DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces\DBAgentSpaceDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\AI\AgentOwnDriveSpaceRightsExtensionService;
use DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Modules\CloudDrive\CloudDriveModule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The default own drive of an agent space: its link table, its kind and its owner's rights */
class AgentOwnDriveTest extends TestCase
{
    public function testTheLinkTableBelongsToTheAgentPart(): void
    {
        self::assertTrue(self::isRequiringAgents(AgentSpaceDriveSpace::class));
        self::assertTrue(self::isRequiringAgents(DBAgentSpaceDriveSpace::class));
        self::assertTrue(self::isRequiringAgents(AgentOwnDriveSpaceRightsExtensionService::class));
        self::assertSame('AgentSpaceDriveSpaces', \DDD\Domain\CloudDrive\Repo\DB\AI\AgentSpaceDriveSpaces\DBAgentSpaceDriveSpaceModel::TABLE_NAME);
    }

    public function testTheOwnDriveIsOfItsOwnKind(): void
    {
        self::assertSame('agentSpace', DriveSpaceMountResolverService::OWN_DRIVE_SPACE_KIND);
        $spaceKinds = require dirname(__DIR__, 4) . '/config/app/CloudDrive/spaceKinds.php';
        self::assertArrayHasKey(DriveSpaceMountResolverService::OWN_DRIVE_SPACE_KIND, $spaceKinds);
    }

    public function testTheOwnerHoldsEveryRightOnItsOwnDrivesOnly(): void
    {
        $agentOwnDriveSpaceRightsExtensionService = new AgentOwnDriveSpaceRightsExtensionService();
        $account = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $account->id = 42;
        foreach ([DBDriveSpace::RIGHT_READ, DBDriveSpace::RIGHT_WRITE, DBDriveSpace::RIGHT_MANAGE] as $right) {
            self::assertNull($agentOwnDriveSpaceRightsExtensionService->isCallerUnrestricted($account, $right));
            $queryBuilder = new DoctrineQueryBuilder($this->createMock(EntityManagerInterface::class));
            $condition = $agentOwnDriveSpaceRightsExtensionService->buildSpaceRightsCondition($queryBuilder, 'space', $right, $account, 'p0_');
            self::assertSame('(space.spaceKind = :p0_ownDriveKind AND space.accountId = :p0_ownDriveAccountId)', $condition);
            self::assertSame('agentSpace', $queryBuilder->getParameter('p0_ownDriveKind')?->getValue());
            self::assertSame(42, $queryBuilder->getParameter('p0_ownDriveAccountId')?->getValue());
        }
        $queryBuilder = new DoctrineQueryBuilder($this->createMock(EntityManagerInterface::class));
        self::assertNull($agentOwnDriveSpaceRightsExtensionService->buildSpaceRightsCondition($queryBuilder, 'space', DBDriveSpace::RIGHT_READ, null, 'p0_'), 'no account, no grant');
    }

    public function testTheDrivesRightIsNamedByThePackage(): void
    {
        self::assertSame('useDrive', CloudDriveAccountRights::USE_DRIVE);
        self::assertSame([CloudDriveAccountRights::USE_DRIVE], CloudDriveAccountRights::RIGHTS);
    }

    protected static function isRequiringAgents(string $className): bool
    {
        foreach (CloudDriveModule::getNamespacesRequiringClasses() as $namespacePrefix => $requiredClassName) {
            if (str_starts_with($className, $namespacePrefix) && $requiredClassName === CloudDriveModule::AGENTS_PACKAGE_CLASS) {
                return true;
            }
        }
        return false;
    }
}
