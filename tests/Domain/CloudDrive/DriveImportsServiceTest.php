<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Imports\DriveImportOptions;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveImportsService;
use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Entities\Files\Sources\FileReferences;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ArrayFileSource;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveChangesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveImportsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveNodesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveSpacesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveVersionsService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\FileImportDriveWorkingCopiesService;
use DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes\ScriptedFileFlowPolicyService;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

/**
 * Files of any file source become drive files through one import: read under the caller's rights, checked by the
 * flow rule, written like an upload with the origin IMPORT and an IMPORTED event naming where they came from.
 */
class DriveImportsServiceTest extends TestCase
{
    protected ArrayFileSource $mediaSource;

    protected ScriptedFileFlowPolicyService $flowPolicy;

    protected FileImportDriveNodesService $nodes;

    protected FileImportDriveSpacesService $spaces;

    protected FileImportDriveVersionsService $versions;

    protected FileImportDriveChangesService $changes;

    protected FileImportDriveWorkingCopiesService $workingCopies;

    protected FileImportDriveImportsService $driveImportsService;

    protected DriveSpace $ownDriveSpace;

    protected DriveSpace $sharedSpace;

    protected function setUp(): void
    {
        $this->mediaSource = new ArrayFileSource('media');
        $this->addMediaFile('901', 'logo-acme-901.png', 'image/png', FileAudience::PUBLIC, 'PNG-BYTES');
        $this->addMediaFile('902', 'route-problem-902.jpg', 'image/jpeg', FileAudience::PERSONAL, 'JPEG-BYTES');
        $fileSourcesService = new FileSourcesService();
        $fileSourcesService->setFileSources([$this->mediaSource]);
        $this->flowPolicy = new ScriptedFileFlowPolicyService();
        $this->nodes = new FileImportDriveNodesService();
        $this->spaces = new FileImportDriveSpacesService();
        $this->versions = new FileImportDriveVersionsService($this->nodes);
        $this->changes = new FileImportDriveChangesService();
        $this->workingCopies = new FileImportDriveWorkingCopiesService();
        $this->driveImportsService = new FileImportDriveImportsService(
            $fileSourcesService,
            $this->flowPolicy,
            $this->nodes,
            $this->spaces,
            $this->versions,
            $this->changes,
            $this->workingCopies
        );
        $this->ownDriveSpace = $this->spaces->addSpace(1, 'Own drive');
        $this->sharedSpace = $this->spaces->addSpace(2, 'Team Musterstadt', DriveSpace::AUDIENCE_SHARED);
    }

    protected function addMediaFile(string $id, string $name, string $contentType, string $audience, string $content, bool $isContentUntrusted = false): FileFacts
    {
        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference('media', $id);
        $fileFacts->name = $name;
        $fileFacts->contentType = $contentType;
        $fileFacts->family = FileFamily::fromContentType($contentType, $name);
        $fileFacts->sizeBytes = strlen($content);
        $fileFacts->audience = $audience;
        $fileFacts->isContentUntrusted = $isContentUntrusted;
        $fileFacts->containerLabel = 'Media library';
        $this->mediaSource->addFile($fileFacts, $content);
        return $fileFacts;
    }

    public function testAMediaItemBecomesADriveFileWithItsOriginAndEvent(): void
    {
        $this->workingCopies->addWorkingCopy(1, 'older');
        $this->workingCopies->addWorkingCopy(2, 'sandbox-of-the-call');
        $this->workingCopies->addWorkingCopy(3, 'newest');
        $driveImportOptions = new DriveImportOptions();
        $driveImportOptions->isOwnDrive = true;
        $driveImportOptions->workingCopyKey = 'sandbox-of-the-call';

        $driveImportResult = $this->driveImportsService->importFiles(FileReferences::fromFileReferences([new FileReference('media', '901')]), $this->ownDriveSpace, '/presentations/acme/assets/', $driveImportOptions);

        self::assertSame([], $driveImportResult->refusedLines);
        $driveImportedFile = $driveImportResult->importedFiles->first();
        self::assertSame('presentations/acme/assets/logo-acme-901.png', $driveImportedFile->path);
        self::assertSame('media/901', $driveImportedFile->sourceFileReference->toString());
        self::assertSame('PNG-BYTES', $this->driveImportsService->storedBytesByFileName['logo-acme-901.png']);

        $driveFileWrite = $this->versions->driveFileWritesByName['logo-acme-901.png'];
        self::assertSame(DriveNode::ORIGIN_IMPORT, $driveFileWrite->origin);
        self::assertSame('imported from Media library (media/901)', $driveFileWrite->comment);
        self::assertFalse($driveFileWrite->isContentUntrusted);
        self::assertSame(5, $driveFileWrite->authorAccountId);
        // no working copy key on the write: every working copy pulls the file as another writer's change
        self::assertNull($driveFileWrite->workingCopyKey);
        self::assertCount(1, $this->versions->ingestedVersionIds);
        self::assertSame([$driveImportedFile->driveNode->id . ' ' . DriveImportsService::EVENT_IMPORTED . ' from Media library (media/901)'], $this->changes->recordedHostEvents);
        // the sandbox of the call and the newest working copy see the file at their next call
        self::assertSame(['sandbox-of-the-call', 'newest'], $this->workingCopies->pullRequestedKeys);

        // the flow rule was asked with the destination: the caller's own drive, internal, the folder
        $fileFlowTarget = $this->flowPolicy->askedTargets[0];
        self::assertSame(FileFlowTarget::KIND_STORE, $fileFlowTarget->kind);
        self::assertSame('drive', $fileFlowTarget->storeName);
        self::assertSame(FileAudience::INTERNAL, $fileFlowTarget->audience);
        self::assertTrue($fileFlowTarget->isOwnContainer);
        self::assertSame('presentations/acme/assets', $fileFlowTarget->folderPath);
        self::assertSame('Own drive', $fileFlowTarget->containerLabel);
    }

    public function testTheFlowRuleRefusesAndARefusedFileNeverStopsTheOthers(): void
    {
        $driveImportResult = $this->driveImportsService->importFiles(
            FileReferences::fromFileReferences([new FileReference('media', '902'), new FileReference('media', '404'), new FileReference('ticket', '1'), new FileReference('media', '901')]),
            $this->ownDriveSpace,
            'assets'
        );
        self::assertSame(1, $driveImportResult->importedFiles->count());
        self::assertSame('assets/logo-acme-901.png', $driveImportResult->importedFiles->first()->path);
        self::assertCount(3, $driveImportResult->refusedLines);
        self::assertStringContainsString('media/902: media/902 was not copied', $driveImportResult->refusedLines[0]);
        self::assertStringContainsString('media/404: media/404 was not found', $driveImportResult->refusedLines[1]);
        self::assertStringContainsString('ticket/1: "ticket" names no file source', $driveImportResult->refusedLines[2]);
        self::assertArrayNotHasKey('route-problem-902.jpg', $this->driveImportsService->storedBytesByFileName);
        self::assertFalse($driveImportResult->needsOwnerConsent);
    }

    public function testACopyTheOwnerHasToAllowWaitsForTheConsent(): void
    {
        $fileFlowDecision = FileFlowDecision::allow();
        $fileFlowDecision->requiresOwnerConsent = true;
        $this->flowPolicy->fileFlowDecision = $fileFlowDecision;

        $withoutConsent = $this->driveImportsService->importFiles(FileReferences::fromFileReferences([new FileReference('media', '901')]), $this->sharedSpace, 'Logos');
        self::assertFalse($withoutConsent->hasImportedFiles());
        self::assertTrue($withoutConsent->needsOwnerConsent);
        self::assertStringContainsString('the owner has to say yes', $withoutConsent->refusedLines[0]);
        self::assertSame(FileAudience::SHARED, $this->flowPolicy->askedTargets[0]->audience);
        self::assertSame([], $this->workingCopies->pullRequestedKeys);

        $driveImportOptions = new DriveImportOptions();
        $driveImportOptions->hasOwnerConsent = true;
        $withConsent = $this->driveImportsService->importFiles(FileReferences::fromFileReferences([new FileReference('media', '901')]), $this->sharedSpace, 'Logos', $driveImportOptions);
        self::assertTrue($withConsent->hasImportedFiles());
        self::assertFalse($withConsent->needsOwnerConsent);
    }

    public function testMetadataGoesAndTheCopyExpiresWhereTheFlowRuleSaysSo(): void
    {
        $imagick = new Imagick();
        $imagick->newImage(30, 10, new ImagickPixel('#1E88E5'));
        $imagick->setImageFormat('JPEG');
        $imagick->setImageProfile('exif', "Exif\0\0" . 'MM' . "\0*" . pack('N', 8) . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, 6, 0) . pack('N', 0));
        $imagick->setImageOrientation(Imagick::ORIENTATION_RIGHTTOP);
        $jpegWithExif = $imagick->getImageBlob();
        $this->addMediaFile('903', 'photo.jpg', 'image/jpeg', FileAudience::INTERNAL, $jpegWithExif, true);

        $fileFlowDecision = FileFlowDecision::allow();
        $fileFlowDecision->stripsMetadata = true;
        $fileFlowDecision->expiresAfterDays = 30;
        $this->flowPolicy->fileFlowDecision = $fileFlowDecision;

        $driveImportResult = $this->driveImportsService->importFiles(FileReferences::fromFileReferences([new FileReference('media', '903')]), $this->ownDriveSpace, 'customer-files/ticket-7');
        $driveImportedFile = $driveImportResult->importedFiles->first();
        self::assertTrue($driveImportedFile->isMetadataRemoved);
        $storedBytes = $this->driveImportsService->storedBytesByFileName['photo.jpg'];
        self::assertNotSame($jpegWithExif, $storedBytes);
        $storedSize = getimagesizefromstring($storedBytes);
        // turned upright before the orientation tag went
        self::assertSame([10, 30], [$storedSize[0], $storedSize[1]]);
        self::assertTrue($this->versions->driveFileWritesByName['photo.jpg']->isContentUntrusted);
        self::assertSame([(int)$driveImportedFile->driveNode->id], $this->driveImportsService->nodeIdsWithStoredExpiry);
        $daysUntilExpiry = ($driveImportedFile->driveNode->expiresAt->getTimestamp() - time()) / 86400;
        self::assertEqualsWithDelta(30, $daysUntilExpiry, 0.01);
        // a folder below customer-files is a destination for a person's own data
        self::assertSame(FileAudience::PERSONAL, $this->flowPolicy->askedTargets[0]->audience);
    }

    public function testAConflictIsARefusedLineAndAFileNameIsMadeSafe(): void
    {
        $this->versions->answersConflict = true;
        $driveImportResult = $this->driveImportsService->importFiles(FileReferences::fromFileReferences([new FileReference('media', '901')]), $this->ownDriveSpace, 'assets');
        self::assertFalse($driveImportResult->hasImportedFiles());
        self::assertStringContainsString('changed at the same moment', $driveImportResult->refusedLines[0]);
        self::assertSame([], $this->changes->recordedHostEvents);

        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference('support', '3301');
        $fileFacts->name = "../../etc/pass\nwd";
        self::assertSame('-..-etc-pass-wd', $this->driveImportsService->buildFileName($fileFacts));
        $fileFacts->name = '';
        self::assertSame('support-3301', $this->driveImportsService->buildFileName($fileFacts));
        $fileFacts->name = str_repeat('a', 300) . '.png';
        self::assertSame(DriveNode::MAX_NAME_LENGTH, mb_strlen($this->driveImportsService->buildFileName($fileFacts)));
        self::assertStringEndsWith('.png', $this->driveImportsService->buildFileName($fileFacts));
    }

    public function testTheCallerNeedsTheWriteRightAndTheFolderStaysInTheSpace(): void
    {
        $readOnlySpace = $this->spaces->addSpace(3, 'Library', DriveSpace::AUDIENCE_INTERNAL, false);
        try {
            $this->driveImportsService->importFiles(FileReferences::fromFileReferences([new FileReference('media', '901')]), $readOnlySpace, 'assets');
            self::fail('an import into a space the caller may not write ran');
        } catch (ForbiddenException) {
            self::assertSame([], $this->driveImportsService->storedBytesByFileName);
        }
        try {
            $this->driveImportsService->importFiles(FileReferences::fromFileReferences([new FileReference('media', '901')]), $this->ownDriveSpace, 'assets/../../x');
            self::fail('a folder path left the space');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('leaves its folder', $badRequestException->getMessage());
        }
        $this->expectException(BadRequestException::class);
        $this->driveImportsService->importFiles(new FileReferences(), $this->ownDriveSpace, 'assets');
    }
}
