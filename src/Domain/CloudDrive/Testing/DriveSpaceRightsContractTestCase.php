<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Testing;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\DBDriveSpaceScopedEntity;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpaceModel;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;

/**
 * The contract a host's space rights fulfil; a host extends this test case in its suite (with a database) and names
 * a space plus its accounts. The host's rules are DriveSpaceRightsExtension services, never a subclass of DriveSpace,
 * DBDriveSpace or DBDriveSpaceModel. A caller without rights reads no row of the space (nodes, feed, cards, content);
 * write is a subset of read and manage of write.
 */
abstract class DriveSpaceRightsContractTestCase extends TestCase
{
    /** A stored space with at least one file (its root folder is created by DriveSpacesService::createSpace()) */
    abstract protected function createSpaceWithOneFile(): DriveSpace;

    /** An account that may not read the space */
    abstract protected function createAccountWithoutRights(DriveSpace $driveSpace): Account;

    /** An account that may read but not write the space; null when the host has no such role */
    abstract protected function createReadOnlyAccount(DriveSpace $driveSpace): ?Account;

    /** An account that may write the space */
    abstract protected function createWriterAccount(DriveSpace $driveSpace): Account;

    /** An account that may write but not manage the space; null when the host has no such role */
    protected function createWriterWithoutManageAccount(DriveSpace $driveSpace): ?Account
    {
        return null;
    }

    protected function tearDown(): void
    {
        // the signed-in account and the space rights are process state: the next test starts without them
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        parent::tearDown();
    }

    public function testACallerWithoutRightsReadsNothingOfTheSpace(): void
    {
        $driveSpace = $this->createSpaceWithOneFile();
        $fileNode = $this->findAnyFileAsSystem($driveSpace);
        $accountWithoutRights = $this->createAccountWithoutRights($driveSpace);
        AuthService::instance()->setAccount($accountWithoutRights);

        self::assertNull($this->getDriveSpacesService()->findSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_READ));
        self::assertNull($this->getDriveNodesService()->find((int)$fileNode->id, false));
        self::assertSame(0, $this->getDriveNodesService()->searchNodesByName([(int)$driveSpace->id], (string)$fileNode->name)->count());
        self::assertSame(0, $this->getDriveChangesService()->findChangesSince((int)$driveSpace->id, 0)->count());
        self::assertSame(0, $this->getDriveContentGrantsService()->findFileCards([$fileNode->buildReference()])->count());
        $this->expectException(NotFoundException::class);
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id);
    }

    public function testWriteImpliesReadAndReadDoesNotImplyWrite(): void
    {
        $driveSpace = $this->createSpaceWithOneFile();
        $writerAccount = $this->createWriterAccount($driveSpace);
        AuthService::instance()->setAccount($writerAccount);
        self::assertNotNull($this->getDriveSpacesService()->findSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE));
        self::assertNotNull($this->getDriveSpacesService()->findSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_READ));
        $readOnlyAccount = $this->createReadOnlyAccount($driveSpace);
        if ($readOnlyAccount === null) {
            return;
        }
        AuthService::instance()->setAccount($readOnlyAccount);
        self::assertNotNull($this->getDriveSpacesService()->findSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_READ));
        $this->expectException(ForbiddenException::class);
        $this->getDriveSpacesService()->requireSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE);
    }

    public function testManageImpliesWriteAndWriteDoesNotImplyManage(): void
    {
        $driveSpace = $this->createSpaceWithOneFile();
        $writerWithoutManageAccount = $this->createWriterWithoutManageAccount($driveSpace);
        if ($writerWithoutManageAccount === null) {
            self::assertTrue(true);
            return;
        }
        AuthService::instance()->setAccount($writerWithoutManageAccount);
        self::assertNotNull($this->getDriveSpacesService()->findSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_WRITE));
        self::assertNull($this->getDriveSpacesService()->findSpaceWithRight((int)$driveSpace->id, DBDriveSpace::RIGHT_MANAGE));
    }

    /** The rights come from DriveSpaceRightsExtension services: a second space repository or model breaks the drive */
    public function testTheSpaceIsNotSubclassedByTheHost(): void
    {
        self::assertSame(DBDriveSpace::class, DBDriveSpaceScopedEntity::getDriveSpaceRepoClass(), 'remove the same-path subclass of DriveSpace / DBDriveSpace; use a DriveSpaceRightsExtension');
        self::assertSame(DBDriveSpaceModel::class, DBDriveSpaceScopedEntity::getDriveSpaceRepoClass()::BASE_ORM_MODEL);
    }

    protected function findAnyFileAsSystem(DriveSpace $driveSpace): DriveNode
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $rootNode = $this->getDriveNodesService()->findRootNode($driveSpace);
            foreach ($this->getDriveNodesService()->findChildren($rootNode)->getElements() as $childNode) {
                if ($childNode->isFile()) {
                    return $childNode;
                }
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::fail('createSpaceWithOneFile() must store a file in the root folder');
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        return $driveNodesService;
    }

    protected function getDriveChangesService(): DriveChangesService
    {
        /** @var DriveChangesService $driveChangesService */
        $driveChangesService = DDDService::instance()->getService(DriveChangesService::class);
        return $driveChangesService;
    }

    protected function getDriveContentGrantsService(): DriveContentGrantsService
    {
        /** @var DriveContentGrantsService $driveContentGrantsService */
        $driveContentGrantsService = DDDService::instance()->getService(DriveContentGrantsService::class);
        return $driveContentGrantsService;
    }
}
