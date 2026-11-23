<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;

/** Names are case-sensitive addresses; the folded name serves search; a trashed node frees its name */
class DriveNodeNamesTest extends TestCase
{
    public function testInvalidNamesAreNamed(): void
    {
        self::assertNull(DriveNode::findInvalidNameReason('Plan (2).md'));
        self::assertNotNull(DriveNode::findInvalidNameReason(''));
        self::assertNotNull(DriveNode::findInvalidNameReason('..'));
        self::assertNotNull(DriveNode::findInvalidNameReason('a/b'));
        self::assertNotNull(DriveNode::findInvalidNameReason("a\nb"));
        self::assertNotNull(DriveNode::findInvalidNameReason(str_repeat('x', 256)));
    }

    public function testTheFoldedNameIgnoresCaseAndAccents(): void
    {
        self::assertSame('grosse ubersicht', DriveNode::foldName('Große  Übersicht'));
    }

    public function testATrashedNameSlotCanNeverBeAName(): void
    {
        self::assertNotNull(DriveNode::findInvalidNameReason(DriveNode::buildTrashedNameSlot(12)));
    }

    public function testPathsSplitIntoNamesAndNeverLeaveTheirFolder(): void
    {
        self::assertSame(['reports', '2026', 'Plan.md'], DriveNodesService::splitPath('/reports//2026/./Plan.md'));
        $this->expectException(BadRequestException::class);
        DriveNodesService::splitPath('reports/../../etc');
    }
}
