<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersionListItem;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\MountNamingDriveSpacesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\SpacesProvidingDriveVersionsService;
use PHPUnit\Framework\TestCase;

/** The notes the drive writes on a version reach a client as a code with parameters, never with internal ids */
class DriveVersionSystemNoteTest extends TestCase
{
    public function testARestoreNoteBecomesACodeWithTheVersionNumber(): void
    {
        $driveFileVersionListItem = $this->buildListItem(sprintf(DriveVersionsService::RESTORED_VERSION_COMMENT, 3), DriveNode::ORIGIN_UPLOAD, 41);
        self::assertSame(DriveFileVersionListItem::COMMENT_CODE_RESTORED_FROM_VERSION, $driveFileVersionListItem->commentCode);
        self::assertSame(3, $driveFileVersionListItem->commentParameters?->versionNumber);
        self::assertNull($driveFileVersionListItem->comment);
    }

    public function testATransferNoteNamesTheSourceSpaceForStaffOnly(): void
    {
        $transferComment = sprintf(DriveVersionsService::TRANSFERRED_VERSION_COMMENT, 957);
        $staffListItem = $this->buildListItem($transferComment, DriveNode::ORIGIN_COPY, null);
        self::assertSame(DriveFileVersionListItem::COMMENT_CODE_TRANSFERRED_FROM_SPACE, $staffListItem->commentCode);
        self::assertSame('Templates', $staffListItem->commentParameters?->sourceSpaceDisplayName);
        self::assertNull($staffListItem->comment, 'the internal space id never reaches the client');

        $partnerListItem = $this->buildListItem($transferComment, DriveNode::ORIGIN_COPY, null, DriveSpace::AUDIENCE_SHARED);
        self::assertSame(DriveFileVersionListItem::COMMENT_CODE_TRANSFERRED_FROM_SPACE, $partnerListItem->commentCode);
        self::assertNull($partnerListItem->commentParameters?->sourceSpaceDisplayName);
    }

    public function testAnAgentCopyNoteKeepsItsPathForStaffOnly(): void
    {
        $staffListItem = $this->buildListItem('copied from /workspace/drive/library/a.txt', DriveNode::ORIGIN_COPY, null);
        self::assertSame(DriveFileVersionListItem::COMMENT_CODE_COPIED_FROM_PATH, $staffListItem->commentCode);
        self::assertSame('/workspace/drive/library/a.txt', $staffListItem->commentParameters?->sourcePath);
        $partnerListItem = $this->buildListItem('copied from /workspace/drive/library/a.txt', DriveNode::ORIGIN_COPY, null, DriveSpace::AUDIENCE_SHARED);
        self::assertNull($partnerListItem->commentParameters?->sourcePath);
    }

    public function testFreeTextThatReadsLikeANoteStaysFreeText(): void
    {
        $restoreLookalike = $this->buildListItem('Restored from v2', DriveNode::ORIGIN_UPLOAD, null);
        self::assertNull($restoreLookalike->commentCode, 'no restored-from version: a person wrote it');
        self::assertSame('Restored from v2', $restoreLookalike->comment);
        $moveLookalike = $this->buildListItem('moved from space 3', DriveNode::ORIGIN_UPLOAD, null);
        self::assertNull($moveLookalike->commentCode);
        self::assertSame('moved from space 3', $moveLookalike->comment);
    }

    protected function buildListItem(string $comment, string $origin, ?int $copiedFromVersionId, string $audience = DriveSpace::AUDIENCE_INTERNAL): DriveFileVersionListItem
    {
        $mountNamingDriveSpacesService = new MountNamingDriveSpacesService();
        /** @var DriveSpace $sourceDriveSpace */
        $sourceDriveSpace = (new \ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $sourceDriveSpace->id = 957;
        $sourceDriveSpace->title = 'Templates';
        $mountNamingDriveSpacesService->addReadableSpace($sourceDriveSpace);
        $mountNamingDriveSpacesService->enableAudience($audience);
        $driveVersionsService = new SpacesProvidingDriveVersionsService($mountNamingDriveSpacesService);
        /** @var DriveFileVersion $driveFileVersion */
        $driveFileVersion = (new \ReflectionClass(DriveFileVersion::class))->newInstanceWithoutConstructor();
        $driveFileVersion->comment = $comment;
        $driveFileVersion->origin = $origin;
        $driveFileVersion->copiedFromVersionId = $copiedFromVersionId;
        $driveFileVersionListItem = new DriveFileVersionListItem();
        $driveFileVersionListItem->comment = $comment;
        $driveVersionsService->applySystemNoteToVersionListItem($driveFileVersionListItem, $driveFileVersion);
        return $driveFileVersionListItem;
    }
}
