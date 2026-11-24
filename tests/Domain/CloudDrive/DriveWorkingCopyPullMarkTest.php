<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use DDD\Domain\CloudDrive\Services\DriveWorkingCopiesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The pull mark of a working copy: set by a writer outside the sandbox, read and consumed by the sandbox's store */
class DriveWorkingCopyPullMarkTest extends TestCase
{
    public function testAMarkIsReadUntilItIsConsumed(): void
    {
        $driveWorkingCopiesService = new DriveWorkingCopiesService();
        $driveWorkingCopy = $this->createWorkingCopy(7, 'eval-' . uniqid());
        self::assertFalse($driveWorkingCopiesService->isPullRequested($driveWorkingCopy));
        $driveWorkingCopiesService->requestPull($driveWorkingCopy);
        self::assertTrue($driveWorkingCopiesService->isPullRequested($driveWorkingCopy));
        self::assertTrue($driveWorkingCopiesService->isPullRequested($driveWorkingCopy), 'reading leaves the mark');
        self::assertTrue($driveWorkingCopiesService->consumePullRequest($driveWorkingCopy));
        self::assertFalse($driveWorkingCopiesService->consumePullRequest($driveWorkingCopy), 'consumed once');
        self::assertFalse($driveWorkingCopiesService->isPullRequested($driveWorkingCopy));
    }

    public function testTheMarkIsPerSpaceAndKey(): void
    {
        $driveWorkingCopiesService = new DriveWorkingCopiesService();
        $workingCopyKey = 'space-' . uniqid();
        $driveWorkingCopiesService->requestPull($this->createWorkingCopy(7, $workingCopyKey));
        self::assertFalse($driveWorkingCopiesService->isPullRequested($this->createWorkingCopy(8, $workingCopyKey)));
        self::assertFalse($driveWorkingCopiesService->isPullRequested($this->createWorkingCopy(7, $workingCopyKey . 'x')));
        self::assertMatchesRegularExpression('/^clouddrive_pull_7_[0-9a-f]{40}$/', $driveWorkingCopiesService->buildPullMarkKey($this->createWorkingCopy(7, 'a/b:c')));
        $driveWorkingCopiesService->consumePullRequest($this->createWorkingCopy(7, $workingCopyKey));
    }

    protected function createWorkingCopy(int $driveSpaceId, string $workingCopyKey): DriveWorkingCopy
    {
        $driveWorkingCopy = (new ReflectionClass(DriveWorkingCopy::class))->newInstanceWithoutConstructor();
        $driveWorkingCopy->driveSpaceId = $driveSpaceId;
        $driveWorkingCopy->workingCopyKey = $workingCopyKey;
        return $driveWorkingCopy;
    }
}
