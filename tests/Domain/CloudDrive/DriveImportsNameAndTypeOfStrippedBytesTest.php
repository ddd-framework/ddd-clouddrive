<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Services\DriveImportsService;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Bytes rewritten by the metadata strip keep a name and a type that fit them: a HEIC picture written as JPEG is
 * stored as `.jpg` with `image/jpeg`; a name that already fits, or bytes of the same type, stay as they are.
 */
class DriveImportsNameAndTypeOfStrippedBytesTest extends TestCase
{
    public function testAHeicNameAndTypeFollowTheJpegBytes(): void
    {
        self::assertSame(
            ['holiday-photo.jpg', 'image/jpeg'],
            $this->buildDriveImportsService()->adaptNameAndTypeToBytes('holiday-photo.heic', 'image/heic', $this->buildJpegBytes())
        );
        self::assertSame(
            ['holiday-photo.jpg', 'image/jpeg'],
            $this->buildDriveImportsService()->adaptNameAndTypeToBytes('holiday-photo.HEIF', 'image/heif', $this->buildJpegBytes())
        );
    }

    public function testANameWithoutExtensionGetsOne(): void
    {
        self::assertSame(
            ['scan.jpg', 'image/jpeg'],
            $this->buildDriveImportsService()->adaptNameAndTypeToBytes('scan', null, $this->buildJpegBytes())
        );
    }

    public function testAFittingNameAndTypeStayAsTheyAre(): void
    {
        $driveImportsService = $this->buildDriveImportsService();
        self::assertSame(['photo.jpeg', 'image/jpeg'], $driveImportsService->adaptNameAndTypeToBytes('photo.jpeg', 'image/jpeg', $this->buildJpegBytes()));
        self::assertSame(['photo.jpeg', 'image/jpeg'], $driveImportsService->adaptNameAndTypeToBytes('photo.jpeg', 'image/pjpeg', $this->buildJpegBytes()), 'the type follows the bytes, the fitting name stays');
    }

    public function testBytesThatAreNoPictureLeaveNameAndType(): void
    {
        self::assertSame(
            ['notes.heic', 'image/heic'],
            $this->buildDriveImportsService()->adaptNameAndTypeToBytes('notes.heic', 'image/heic', 'plain text')
        );
    }

    protected function buildDriveImportsService(): DriveImportsService
    {
        /** @var DriveImportsService $driveImportsService */
        $driveImportsService = (new ReflectionClass(DriveImportsService::class))->newInstanceWithoutConstructor();
        return $driveImportsService;
    }

    protected function buildJpegBytes(): string
    {
        $imagick = new Imagick();
        $imagick->newImage(4, 4, new ImagickPixel('red'));
        $imagick->setImageFormat('JPEG');
        $bytes = $imagick->getImageBlob();
        $imagick->clear();
        return $bytes;
    }
}
