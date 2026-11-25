<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Renditions\DriveOfficeRendition;
use DDD\Domain\CloudDrive\Services\DriveOfficeRenditionsService;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The one test that starts LibreOffice: a tiny docx becomes a PDF through the worker's whole path (isolated process,
 * throwaway profile, page range). Skipped where `soffice` is not installed; run it in the image of the render worker.
 */
class DriveOfficeRenditionConversionTest extends DriveOfficeRenditionTestCase
{
    protected function setUp(): void
    {
        $officeBinary = (new ExecutableFinder())->find('soffice', null, ['/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin', '/Applications/LibreOffice.app/Contents/MacOS']);
        if ($officeBinary === null) {
            self::markTestSkipped('soffice (LibreOffice) is not installed here.');
        }
        parent::setUp();
        $this->driveOfficeRenditionsService->isConverterReal = true;
        $this->driveOfficeRenditionsService->testSettings->binary = $officeBinary;
    }

    /** A docx of `$pageCount` pages, each with its own line of text */
    protected function buildDocxOfPages(int $pageCount): string
    {
        $paragraphs = '';
        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            if ($pageNumber > 1) {
                $paragraphs .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            $paragraphs .= '<w:p><w:r><w:t>Rendition page ' . $pageNumber . ': ' . bin2hex(random_bytes(200)) . '</w:t></w:r></w:p>';
        }
        return $this->buildContainer([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $paragraphs . '</w:body></w:document>',
        ]);
    }

    protected function renderStoredFile(string $docxBytes): string
    {
        $renditionStorageKey = DriveOfficeRenditionsService::buildRenditionStorageKey(self::BLOB_STORAGE_KEY);
        $this->objectStorage->deleteObject($renditionStorageKey);
        $driveNode = $this->storeFile('Report.docx', $docxBytes);
        self::assertSame(DriveOfficeRendition::STATE_PENDING, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        $renderAnswer = $this->driveOfficeRenditionsService->renderRendition($this->driveVersionsService->versionsById[70]);
        self::assertSame(
            DriveOfficeRendition::STATE_READY,
            $renderAnswer->state,
            'LibreOffice did not convert the docx: ' . (string)$this->objectStorage->getObjectContent(DriveOfficeRenditionsService::buildFailureMarkerStorageKey(self::BLOB_STORAGE_KEY))
        );
        self::assertDirectoryDoesNotExist((string)$this->driveOfficeRenditionsService->lastWorkingDirectory);
        self::assertFalse($this->objectStorage->objectExists(DriveOfficeRenditionsService::buildPendingMarkerStorageKey(self::BLOB_STORAGE_KEY)));
        self::assertSame(DriveOfficeRendition::STATE_READY, $this->driveOfficeRenditionsService->requestRendition($driveNode)->state);
        return (string)$this->objectStorage->getObjectContent($renditionStorageKey);
    }

    public function testLibreOfficeConvertsATinyDocxAndKeepsThePageCap(): void
    {
        $docxBytes = $this->buildDocxOfPages(3);

        $wholeRendition = $this->renderStoredFile($docxBytes);
        self::assertStringStartsWith('%PDF-', $wholeRendition);
        self::assertGreaterThan(1000, strlen($wholeRendition));

        $this->driveOfficeRenditionsService->testSettings->maxPages = 1;
        $cappedRendition = $this->renderStoredFile($docxBytes);
        self::assertStringStartsWith('%PDF-', $cappedRendition);
        self::assertLessThan(strlen($wholeRendition), strlen($cappedRendition), 'one page of three is a smaller PDF');
    }
}
