<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Content\DriveContentGrant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace;
use DDD\Domain\CloudDrive\Services\DriveContentGrantsService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Who may do what as which audience, and which content variants a reader gets (entities built without their lazy-load setup) */
class DriveAudienceTest extends TestCase
{
    public function testTheAudienceIsSetRestoredAndReset(): void
    {
        $driveSpacesService = new DriveSpacesService();
        self::assertSame(DriveSpace::AUDIENCE_INTERNAL, $driveSpacesService->getCurrentAudience());
        $previousAudience = $driveSpacesService->enableAudience(DriveSpace::AUDIENCE_SHARED);
        self::assertSame(DriveSpace::AUDIENCE_INTERNAL, $previousAudience);
        self::assertSame(DriveSpace::AUDIENCE_SHARED, $driveSpacesService->getCurrentAudience());
        $driveSpacesService->restoreAudience($previousAudience);
        self::assertSame(DriveSpace::AUDIENCE_INTERNAL, $driveSpacesService->getCurrentAudience());
        $driveSpacesService->enableAudience(DriveSpace::AUDIENCE_SHARED);
        $driveSpacesService->resetWorkerStateForNewJob();
        self::assertSame(DriveSpace::AUDIENCE_INTERNAL, $driveSpacesService->getCurrentAudience(), 'a new job starts as the staff');
        $driveSpacesService->enableAudience(DriveSpace::AUDIENCE_SHARED);
        $driveSpacesService->reset();
        self::assertSame(DriveSpace::AUDIENCE_INTERNAL, $driveSpacesService->getCurrentAudience(), 'a new request starts as the staff');
        $this->expectException(BadRequestException::class);
        $driveSpacesService->enableAudience('PARTNER');
    }

    public function testTheSharedAudienceReadsWritesUnlessLockedAndNeverManages(): void
    {
        $driveSpacesService = new DriveSpacesService();
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->isAudienceWriteLocked = false;
        self::assertTrue($driveSpacesService->isRightAllowedForAudience($driveSpace, DBDriveSpace::RIGHT_WRITE, DriveSpace::AUDIENCE_SHARED));
        self::assertFalse($driveSpacesService->isRightAllowedForAudience($driveSpace, DBDriveSpace::RIGHT_MANAGE, DriveSpace::AUDIENCE_SHARED));
        $driveSpace->isAudienceWriteLocked = true;
        self::assertTrue($driveSpacesService->isRightAllowedForAudience($driveSpace, DBDriveSpace::RIGHT_READ, DriveSpace::AUDIENCE_SHARED));
        self::assertFalse($driveSpacesService->isRightAllowedForAudience($driveSpace, DBDriveSpace::RIGHT_WRITE, DriveSpace::AUDIENCE_SHARED));
        self::assertTrue($driveSpacesService->isRightAllowedForAudience($driveSpace, DBDriveSpace::RIGHT_WRITE, DriveSpace::AUDIENCE_INTERNAL), 'the staff still writes');
        self::assertTrue($driveSpacesService->isRightAllowedForAudience($driveSpace, DBDriveSpace::RIGHT_MANAGE, DriveSpace::AUDIENCE_INTERNAL));
    }

    public function testATrashedFileIsOnlyTheStaffsDownload(): void
    {
        $driveContentGrantsService = new DriveContentGrantsService();
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->nodeType = DriveNode::NODE_TYPE_FILE;
        foreach (DriveContentGrant::PEOPLE_PURPOSES as $variant) {
            self::assertTrue($driveContentGrantsService->isVariantAllowed($driveNode, $variant, DriveSpace::AUDIENCE_SHARED), $variant);
        }
        $driveNode->trashedAt = DateTime::fromTimestamp(time());
        self::assertTrue($driveContentGrantsService->isVariantAllowed($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD, DriveSpace::AUDIENCE_INTERNAL));
        self::assertFalse($driveContentGrantsService->isVariantAllowed($driveNode, DriveContentGrant::PURPOSE_INLINE, DriveSpace::AUDIENCE_INTERNAL));
        self::assertFalse($driveContentGrantsService->isVariantAllowed($driveNode, DriveContentGrant::PURPOSE_THUMBNAIL, DriveSpace::AUDIENCE_INTERNAL));
        self::assertFalse($driveContentGrantsService->isVariantAllowed($driveNode, DriveContentGrant::PURPOSE_DOWNLOAD, DriveSpace::AUDIENCE_SHARED));
    }
}
