<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEvent;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEventListItem;
use DDD\Domain\CloudDrive\Entities\Changes\DriveChangeEventListItems;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNodes;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Transfers\DriveTransferredNodes;
use DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy;
use DDD\Domain\CloudDrive\Services\DriveActivityEventsService;
use DDD\Domain\CloudDrive\Services\DriveChangesService;
use DDD\Domain\CloudDrive\Services\DriveNodeLifecycleListenersService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ExposedDriveSpaceTransfersService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\LinkReportingDriveSpaceTransferListener;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\RecordingDriveNodeLifecycleListener;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Host events, the merged history, the purge and transfer listeners, what a transfer refuses */
class DriveActivityAndListenersTest extends TestCase
{
    public function testHostEventTypesAreTheApplicationsOwn(): void
    {
        $driveActivityEventsService = new DriveActivityEventsService();
        $driveNode = $this->buildNode(5);
        foreach (['linked', 'X', 'LINKED-TO', DriveChangeEvent::EVENT_CREATED, DriveChangeEvent::EVENT_VIEWED, str_repeat('A', 65)] as $invalidEventType) {
            try {
                $driveActivityEventsService->recordHostEvent($driveNode, $invalidEventType, null, 1, null, DriveSpace::AUDIENCE_INTERNAL);
                self::fail($invalidEventType . ' was taken');
            } catch (BadRequestException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, preg_match(DriveActivityEventsService::HOST_EVENT_TYPE_PATTERN, 'IMPORTED_TO_MEDIA_LIBRARY'));
    }

    public function testTheHistoryIsNewestFirstAndKeepsChangesAndActivitiesApart(): void
    {
        $driveChangesService = new DriveChangesService();
        $olderChange = $this->buildListItem(7, DriveChangeEventListItem::SOURCE_CHANGE, 1000);
        $sameSecondChange = $this->buildListItem(8, DriveChangeEventListItem::SOURCE_CHANGE, 2000);
        $sameSecondAccess = $this->buildListItem(8, DriveChangeEventListItem::SOURCE_ACTIVITY, 2000);
        $newestAccess = $this->buildListItem(3, DriveChangeEventListItem::SOURCE_ACTIVITY, 3000);
        $historyListItems = [$olderChange, $sameSecondChange, $newestAccess, $sameSecondAccess];
        usort($historyListItems, [$driveChangesService, 'compareHistoryListItemsNewestFirst']);
        self::assertSame([$newestAccess, $sameSecondAccess, $sameSecondChange, $olderChange], $historyListItems);
        $driveChangeEventListItems = new DriveChangeEventListItems();
        $driveChangeEventListItems->add($sameSecondChange);
        $driveChangeEventListItems->add($sameSecondAccess);
        self::assertSame(2, $driveChangeEventListItems->count(), 'a change and an activity with the same id are two entries');
        $this->expectException(BadRequestException::class);
        $driveChangesService->buildNodeHistory($this->buildNode(5), 20, null, 'everything');
    }

    public function testPurgeListenersGetEveryIdOnceAndAFailingOneIsSkipped(): void
    {
        $failingListener = new RecordingDriveNodeLifecycleListener(true);
        $recordingListener = new RecordingDriveNodeLifecycleListener();
        $driveNodeLifecycleListenersService = new DriveNodeLifecycleListenersService();
        $driveNodeLifecycleListenersService->setListeners([$failingListener, $recordingListener]);
        $driveNodeLifecycleListenersService->notifyNodesPurged($this->buildSpace(), [4, 4, 9, 0]);
        self::assertSame([4, 9], $recordingListener->purgedNodeIds);
        $driveNodeLifecycleListenersService->notifyNodesPurged($this->buildSpace(), []);
        self::assertSame([4, 9], $recordingListener->purgedNodeIds, 'nothing purged, nobody called');
    }

    public function testATransferPreviewCollectsLostLinksAndSkipsAFailingListener(): void
    {
        $exposedDriveSpaceTransfersService = new ExposedDriveSpaceTransfersService();
        $exposedDriveSpaceTransfersService->setListeners([new LinkReportingDriveSpaceTransferListener(true), new LinkReportingDriveSpaceTransferListener()]);
        $driveNodes = new DriveNodes();
        $firstDriveNode = $this->buildNode(11);
        $secondDriveNode = $this->buildNode(12);
        $driveNodes->add($firstDriveNode);
        $driveNodes->add($secondDriveNode);
        $driveTransferLostLinks = $exposedDriveSpaceTransfersService->findLinksLostOnTransferForTest($driveNodes, $this->buildSpace());
        self::assertSame(2, $driveTransferLostLinks->count());
        $driveTransferredNodes = new DriveTransferredNodes();
        self::assertNull($driveTransferredNodes->findTargetDriveNodeId(11));
    }

    public function testATransferRefusesWhatTheTargetDoesNotTakeOrWhatIsNotReleased(): void
    {
        $exposedDriveSpaceTransfersService = new ExposedDriveSpaceTransfersService();
        $driveTypePolicy = new DriveTypePolicy();
        $driveTypePolicy->allowedExtensions = ['pdf'];
        $driveTypePolicy->maxFileSizeBytes = 100;
        $releasedPdf = $this->buildNode(1, 'a.pdf', 50, 10);
        self::assertNull($exposedDriveSpaceTransfersService->findFileRefusalReasonForTest($releasedPdf, $driveTypePolicy));
        self::assertNotNull($exposedDriveSpaceTransfersService->findFileRefusalReasonForTest($this->buildNode(2, 'a.exe', 50, 10), $driveTypePolicy));
        self::assertNotNull($exposedDriveSpaceTransfersService->findFileRefusalReasonForTest($this->buildNode(3, 'big.pdf', 500, 10), $driveTypePolicy));
        self::assertNotNull($exposedDriveSpaceTransfersService->findFileRefusalReasonForTest($this->buildNode(4, 'pending.pdf', 50, null), $driveTypePolicy));
    }

    protected function buildNode(int $driveNodeId, string $name = 'file.pdf', int $sizeBytes = 10, ?int $currentVersionId = 1): DriveNode
    {
        /** @var DriveNode $driveNode */
        $driveNode = (new ReflectionClass(DriveNode::class))->newInstanceWithoutConstructor();
        $driveNode->id = $driveNodeId;
        $driveNode->driveSpaceId = 3;
        $driveNode->nodeType = DriveNode::NODE_TYPE_FILE;
        $driveNode->name = $name;
        $driveNode->sizeBytes = $sizeBytes;
        $driveNode->currentVersionId = $currentVersionId;
        return $driveNode;
    }

    protected function buildSpace(): DriveSpace
    {
        /** @var DriveSpace $driveSpace */
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->id = 3;
        return $driveSpace;
    }

    protected function buildListItem(int $id, string $eventSource, int $timestamp): DriveChangeEventListItem
    {
        $driveChangeEventListItem = new DriveChangeEventListItem();
        $driveChangeEventListItem->id = $id;
        $driveChangeEventListItem->eventSource = $eventSource;
        $driveChangeEventListItem->occurredAt = DateTime::fromTimestamp($timestamp);
        return $driveChangeEventListItem;
    }
}
