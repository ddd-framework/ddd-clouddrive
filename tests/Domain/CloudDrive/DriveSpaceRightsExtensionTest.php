<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpaceRightsExtensionsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ScriptedDriveSpaceRightsExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** The host's space rights as extensions: composition, fail-closed, the repository's bool contract */
class DriveSpaceRightsExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->getDriveSpaceRightsExtensionsService()->setExtensions([]);
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        parent::tearDown();
    }

    public function testWithoutExtensionsTheDefaultIsUnchanged(): void
    {
        $driveSpaceRightsExtensionsService = new DriveSpaceRightsExtensionsService();
        self::assertTrue($driveSpaceRightsExtensionsService->isCallerUnrestricted(null, DBDriveSpace::RIGHT_READ, true), 'an admin holds every right');
        self::assertFalse($driveSpaceRightsExtensionsService->isCallerUnrestricted(null, DBDriveSpace::RIGHT_READ, false));
        $queryBuilder = $this->createQueryBuilder();
        self::assertTrue($driveSpaceRightsExtensionsService->applyGrantedSpacesCondition($queryBuilder, 'space', DBDriveSpace::RIGHT_READ, null));
        self::assertSame('space.id IS NULL', (string)$queryBuilder->getDQLPart('where'), 'no grant = no space');
    }

    public function testAnExtensionWidensReadAndKeepsWriteStricter(): void
    {
        $scriptedDriveSpaceRightsExtension = new ScriptedDriveSpaceRightsExtension();
        $scriptedDriveSpaceRightsExtension->grantedSpaceKindByRight = [DBDriveSpace::RIGHT_READ => 'team'];
        $driveSpaceRightsExtensionsService = new DriveSpaceRightsExtensionsService();
        $driveSpaceRightsExtensionsService->setExtensions([$scriptedDriveSpaceRightsExtension, new ScriptedDriveSpaceRightsExtension()]);

        $readQueryBuilder = $this->createQueryBuilder();
        $driveSpaceRightsExtensionsService->applyGrantedSpacesCondition($readQueryBuilder, 'space', DBDriveSpace::RIGHT_READ, null);
        self::assertSame('((space.spaceKind = :space_rightsExtension0_spaceKind))', (string)$readQueryBuilder->getDQLPart('where'));
        self::assertSame('team', $readQueryBuilder->getParameter('space_rightsExtension0_spaceKind')?->getValue());

        $writeQueryBuilder = $this->createQueryBuilder();
        $driveSpaceRightsExtensionsService->applyGrantedSpacesCondition($writeQueryBuilder, 'space', DBDriveSpace::RIGHT_WRITE, null);
        self::assertSame('space.id IS NULL', (string)$writeQueryBuilder->getDQLPart('where'), 'read grant gives no write');
    }

    public function testGrantsOfSeveralExtensionsAreOrComposed(): void
    {
        $teamExtension = new ScriptedDriveSpaceRightsExtension();
        $teamExtension->grantedSpaceKindByRight = [DBDriveSpace::RIGHT_READ => 'team'];
        $libraryExtension = new ScriptedDriveSpaceRightsExtension();
        $libraryExtension->grantedSpaceKindByRight = [DBDriveSpace::RIGHT_READ => 'library'];
        $driveSpaceRightsExtensionsService = new DriveSpaceRightsExtensionsService();
        $driveSpaceRightsExtensionsService->setExtensions([$teamExtension, $libraryExtension]);
        $queryBuilder = $this->createQueryBuilder();
        $driveSpaceRightsExtensionsService->applyGrantedSpacesCondition($queryBuilder, 'space', DBDriveSpace::RIGHT_READ, null);
        self::assertSame(
            '((space.spaceKind = :space_rightsExtension0_spaceKind) OR (space.spaceKind = :space_rightsExtension1_spaceKind))',
            (string)$queryBuilder->getDQLPart('where')
        );
    }

    public function testOneRestrictionBeatsAnyUnrestrictedAnswerAndTheAdminDefault(): void
    {
        $grantingExtension = new ScriptedDriveSpaceRightsExtension();
        $grantingExtension->isUnrestrictedByRight = [DBDriveSpace::RIGHT_READ => true];
        $restrictingExtension = new ScriptedDriveSpaceRightsExtension();
        $restrictingExtension->isUnrestrictedByRight = [DBDriveSpace::RIGHT_READ => false];
        $driveSpaceRightsExtensionsService = new DriveSpaceRightsExtensionsService();
        $driveSpaceRightsExtensionsService->setExtensions([$grantingExtension]);
        self::assertTrue($driveSpaceRightsExtensionsService->isCallerUnrestricted(null, DBDriveSpace::RIGHT_READ, false), 'an extension may lift a non-admin');
        $driveSpaceRightsExtensionsService->setExtensions([$grantingExtension, $restrictingExtension]);
        self::assertFalse($driveSpaceRightsExtensionsService->isCallerUnrestricted(null, DBDriveSpace::RIGHT_READ, true), 'a restriction also binds an admin');
    }

    public function testAThrowingExtensionFailsClosed(): void
    {
        $throwingExtension = new ScriptedDriveSpaceRightsExtension();
        $throwingExtension->throws = true;
        $driveSpaceRightsExtensionsService = new DriveSpaceRightsExtensionsService();
        $driveSpaceRightsExtensionsService->setExtensions([$throwingExtension]);
        self::assertFalse($driveSpaceRightsExtensionsService->isCallerUnrestricted(null, DBDriveSpace::RIGHT_READ, true));
        $queryBuilder = $this->createQueryBuilder();
        $driveSpaceRightsExtensionsService->applyGrantedSpacesCondition($queryBuilder, 'space', DBDriveSpace::RIGHT_READ, null);
        self::assertSame('space.id IS NULL', (string)$queryBuilder->getDQLPart('where'));
    }

    public function testTheRepositoryReportsAddedConditionsAsRestricted(): void
    {
        $scriptedDriveSpaceRightsExtension = new ScriptedDriveSpaceRightsExtension();
        $scriptedDriveSpaceRightsExtension->grantedSpaceKindByRight = [DBDriveSpace::RIGHT_MANAGE => 'team'];
        $this->getDriveSpaceRightsExtensionsService()->setExtensions([$scriptedDriveSpaceRightsExtension]);
        $queryBuilder = $this->createQueryBuilder();
        self::assertTrue(DBDriveSpace::applyRightsToSpaceQuery($queryBuilder, 'space', DBDriveSpace::RIGHT_MANAGE), 'conditions added = true (expand, update, delete keep them)');
        self::assertSame('((space.spaceKind = :space_rightsExtension0_spaceKind))', (string)$queryBuilder->getDQLPart('where'));

        $scriptedDriveSpaceRightsExtension->isUnrestrictedByRight = [DBDriveSpace::RIGHT_MANAGE => true];
        $unrestrictedQueryBuilder = $this->createQueryBuilder();
        self::assertFalse(DBDriveSpace::applyRightsToSpaceQuery($unrestrictedQueryBuilder, 'space', DBDriveSpace::RIGHT_MANAGE), 'no condition = false');
        self::assertNull($unrestrictedQueryBuilder->getDQLPart('where'));
    }

    protected function getDriveSpaceRightsExtensionsService(): DriveSpaceRightsExtensionsService
    {
        return DBDriveSpace::getDriveSpaceRightsExtensionsService();
    }

    protected function createQueryBuilder(): DoctrineQueryBuilder
    {
        return new DoctrineQueryBuilder($this->createMock(EntityManagerInterface::class));
    }
}
