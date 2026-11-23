<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveLocationReference;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Exceptions\DriveLocationNotFoundException;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\InMemoryTreeDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\MountNamingDriveSpacesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Pasted locations (path bar, deep links, agent paths) and the host's deep links */
class DriveLocationResolutionTest extends TestCase
{
    public function testPastedLocationsAreNormalised(): void
    {
        $pathReference = DriveLocationReference::fromLocation(' /Templates//Typst/./letter.typ ');
        self::assertSame('Templates/Typst/letter.typ', $pathReference->path);
        self::assertNull($pathReference->mountName);
        self::assertFalse($pathReference->isNodeReference());

        self::assertSame(812, DriveLocationReference::fromLocation('drive/812')->driveNodeId);
        self::assertSame(812, DriveLocationReference::fromLocation('/drive/812/')->driveNodeId);

        $mountReference = DriveLocationReference::fromLocation('drivespaces/library/Brand/Logo.svg');
        self::assertSame('library', $mountReference->mountName);
        self::assertSame('Brand/Logo.svg', $mountReference->path);
        self::assertNull($mountReference->pathWhenMountIsUnknown);

        $sandboxReference = DriveLocationReference::fromLocation('/workspace/drive/Reports/2026/plan.md');
        self::assertSame('Reports', $sandboxReference->mountName, 'a mount is tried first');
        self::assertSame('2026/plan.md', $sandboxReference->path);
        self::assertSame('Reports/2026/plan.md', $sandboxReference->pathWhenMountIsUnknown, 'else the sandbox\'s own drive');

        self::assertSame('notes/todo.md', DriveLocationReference::fromLocation('/workspace/notes/todo.md')->path);
        self::assertSame('', DriveLocationReference::fromLocation('/workspace/drive')->path);
        self::assertSame('', DriveLocationReference::fromLocation('/')->path);
    }

    public function testAPathAReferenceAndAMountPathResolveToTheirSpace(): void
    {
        [$driveNodesService, $ownDriveSpace] = $this->buildTwoSpaces();

        $folderResolution = $driveNodesService->resolveLocation($ownDriveSpace, '/Templates/Typst');
        self::assertSame(12, (int)$folderResolution->driveSpace->id);
        self::assertSame(3, (int)$folderResolution->driveNode->id);
        self::assertSame('Templates/Typst', $folderResolution->path);

        self::assertSame(4, (int)$driveNodesService->resolveLocation($ownDriveSpace, 'Templates/Typst/letter.typ')->driveNode->id);
        self::assertSame(1, (int)$driveNodesService->resolveLocation($ownDriveSpace, '/')->driveNode->id, 'the root');

        $referenceResolution = $driveNodesService->resolveLocation($ownDriveSpace, 'drive/22');
        self::assertSame(20, (int)$referenceResolution->driveSpace->id, 'a reference answers the space of its node');
        self::assertSame('Brand/Logo.svg', $referenceResolution->path);

        $mountResolution = $driveNodesService->resolveLocation($ownDriveSpace, 'drivespaces/library/Brand');
        self::assertSame(20, (int)$mountResolution->driveSpace->id);
        self::assertSame(21, (int)$mountResolution->driveNode->id);

        $sandboxMountResolution = $driveNodesService->resolveLocation($ownDriveSpace, '/workspace/drive/library/Brand/Logo.svg');
        self::assertSame(22, (int)$sandboxMountResolution->driveNode->id);

        $sandboxOwnResolution = $driveNodesService->resolveLocation($ownDriveSpace, '/workspace/drive/Templates/Typst');
        self::assertSame(12, (int)$sandboxOwnResolution->driveSpace->id, 'not a mount: the requested space');
        self::assertSame(3, (int)$sandboxOwnResolution->driveNode->id);
    }

    public function testAMissingSegmentNamesItselfAndTheDeepestFolder(): void
    {
        [$driveNodesService, $ownDriveSpace] = $this->buildTwoSpaces();
        try {
            $driveNodesService->resolveLocation($ownDriveSpace, '/Templates/Typst/Old/letter.typ');
            self::fail('a missing folder must not resolve');
        } catch (DriveLocationNotFoundException $driveLocationNotFoundException) {
            self::assertSame(404, $driveLocationNotFoundException->getCode());
            self::assertSame('Old', $driveLocationNotFoundException->missingSegment);
            self::assertSame(3, $driveLocationNotFoundException->deepestFolder?->id);
            self::assertSame(12, $driveLocationNotFoundException->driveSpaceId);
        }
        try {
            $driveNodesService->resolveLocation($ownDriveSpace, 'Templates/Typst/letter.typ/more');
            self::fail('a path below a file must not resolve');
        } catch (DriveLocationNotFoundException $driveLocationNotFoundException) {
            self::assertSame('more', $driveLocationNotFoundException->missingSegment);
            self::assertNull($driveLocationNotFoundException->deepestFolder, 'a file is no folder');
        }
        try {
            $driveNodesService->resolveLocation($ownDriveSpace, 'drivespaces/space-99/x');
            self::fail('an unknown mount must not resolve');
        } catch (DriveLocationNotFoundException $driveLocationNotFoundException) {
            self::assertSame('space-99', $driveLocationNotFoundException->missingSegment);
        }
        try {
            $driveNodesService->resolveLocation($ownDriveSpace, 'drive/999');
            self::fail('an unknown reference must not resolve');
        } catch (DriveLocationNotFoundException $driveLocationNotFoundException) {
            self::assertSame('drive/999', $driveLocationNotFoundException->missingSegment);
        }
        $this->expectException(BadRequestException::class);
        $driveNodesService->resolveLocation($ownDriveSpace, 'Templates/../../etc');
    }

    public function testATrashedNodeResolvesByReferenceForTheStaffOnly(): void
    {
        [$driveNodesService, $ownDriveSpace] = $this->buildTwoSpaces();
        $driveNodesService->driveNodesById[4]->trashedAt = DateTime::fromString('2026-11-01 10:00:00');
        self::assertSame(4, (int)$driveNodesService->resolveLocation($ownDriveSpace, 'drive/4')->driveNode->id);
        $previousAudience = $driveNodesService->mountNamingDriveSpacesService->enableAudience(DriveSpace::AUDIENCE_SHARED);
        try {
            $this->expectException(DriveLocationNotFoundException::class);
            $driveNodesService->resolveLocation($ownDriveSpace, 'drive/4');
        } finally {
            $driveNodesService->mountNamingDriveSpacesService->restoreAudience($previousAudience);
        }
    }

    public function testTheAdminLinkTemplateIsFilled(): void
    {
        self::assertSame(
            '/admin/drive?space=12&path=%2FTemplates%2FTypst%2Fletter%20v2.typ&file=4&folder=3',
            DriveNodesService::fillAdminLinkTemplate('/admin/drive?space={spaceId}&path={path}&file={nodeId}&folder={folderNodeId}', 12, 4, 3, 'Templates/Typst/letter v2.typ')
        );
        self::assertSame('/d/7?folder=', DriveNodesService::fillAdminLinkTemplate('/d/{spaceId}?folder={folderNodeId}', 7, null, null, ''));
        self::assertNull((new DriveNodesService())->getAdminLinkTemplate(), 'no template by default');
    }

    /**
     * Space 12 (`space-12`): /Templates/Typst/letter.typ; space 20 (`library`): /Brand/Logo.svg.
     *
     * @return array{0: InMemoryTreeDriveNodesService, 1: DriveSpace}
     */
    protected function buildTwoSpaces(): array
    {
        $mountNamingDriveSpacesService = new MountNamingDriveSpacesService();
        $ownDriveSpace = $this->buildSpace(12, 'personal');
        $mountNamingDriveSpacesService->addReadableSpace($ownDriveSpace);
        $mountNamingDriveSpacesService->addReadableSpace($this->buildSpace(20, 'library'));
        $driveNodesService = new InMemoryTreeDriveNodesService($mountNamingDriveSpacesService);
        $driveNodesService->addNode(1, 12, null, '');
        $driveNodesService->addNode(2, 12, 1, 'Templates');
        $driveNodesService->addNode(3, 12, 2, 'Typst');
        $driveNodesService->addNode(4, 12, 3, 'letter.typ', DriveNode::NODE_TYPE_FILE);
        $driveNodesService->addNode(5, 20, null, '');
        $driveNodesService->addNode(21, 20, 5, 'Brand');
        $driveNodesService->addNode(22, 20, 21, 'Logo.svg', DriveNode::NODE_TYPE_FILE);
        return [$driveNodesService, $ownDriveSpace];
    }

    protected function buildSpace(int $driveSpaceId, string $spaceKind): DriveSpace
    {
        /** @var DriveSpace $driveSpace */
        $driveSpace = (new ReflectionClass(DriveSpace::class))->newInstanceWithoutConstructor();
        $driveSpace->id = $driveSpaceId;
        $driveSpace->spaceKind = $spaceKind;
        return $driveSpace;
    }
}
