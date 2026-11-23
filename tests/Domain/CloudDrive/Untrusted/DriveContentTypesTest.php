<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Untrusted;

use DDD\Domain\CloudDrive\Services\DriveBlobsService;
use DDD\Domain\CloudDrive\Services\DriveTextMergeService;
use DDD\Domain\CloudDrive\Entities\Merges\DriveTextMergeResult;
use PHPUnit\Framework\TestCase;

/**
 * The type a blob keeps comes from its bytes; a merge runs as an isolated process.
 */
class DriveContentTypesTest extends TestCase
{
    public function testTypeComesFromTheBytes(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'drive-type');
        try {
            $gdImage = imagecreatetruecolor(2, 2);
            imagepng($gdImage, $filePath);
            $this->assertSame('image/png', DriveBlobsService::detectContentTypeOfFile($filePath, 'text/html'));
            file_put_contents($filePath, '<html><script>alert(1)</script></html>');
            $this->assertSame('application/octet-stream', DriveBlobsService::detectContentTypeOfFile($filePath, 'image/png'));
            $this->assertSame('text/html', DriveBlobsService::detectContentTypeOfFile($filePath, 'Text/HTML; charset=utf-8'));
            $this->assertSame('application/octet-stream', DriveBlobsService::detectContentTypeOfFile($filePath, null));
        } finally {
            @unlink($filePath);
        }
    }

    public function testMergeStillMergesThroughTheIsolatedProcess(): void
    {
        $driveTextMergeResult = (new DriveTextMergeService())->mergeTexts("a\nb\nc\n", "A\nb\nc\n", "a\nb\nC\n");
        $this->assertSame(DriveTextMergeResult::OUTCOME_MERGED, $driveTextMergeResult->outcome);
        $this->assertSame("A\nb\nC\n", $driveTextMergeResult->mergedText);
        $conflictResult = (new DriveTextMergeService())->mergeTexts("a\n", "b\n", "c\n");
        $this->assertSame(DriveTextMergeResult::OUTCOME_CONFLICT, $conflictResult->outcome);
    }
}
