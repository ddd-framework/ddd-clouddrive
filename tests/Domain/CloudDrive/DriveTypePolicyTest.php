<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Nodes\DriveFileFamily;
use DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy;
use DDD\Domain\CloudDrive\Services\DriveTypePoliciesService;
use PHPUnit\Framework\TestCase;

/** The type policy of a space: names, announced types, sizes, the bytes against the name, safe names */
class DriveTypePolicyTest extends TestCase
{
    public function testAPolicyWithoutListsRestrictsNothing(): void
    {
        $driveTypePolicy = new DriveTypePolicy();
        self::assertFalse($driveTypePolicy->isRestricted());
        self::assertNull($driveTypePolicy->findFileNameRefusalReason('run.exe'));
        self::assertNull($driveTypePolicy->findContentRefusalReason('report.pdf', 'image/png', 'application/x-dosexec'), 'an unrestricted space keeps today\'s behaviour');
    }

    public function testNamesAreCheckedByExtensionAndFamily(): void
    {
        $driveTypePolicy = $this->buildPolicy(['allowedExtensions' => ['.PDF', 'xlsx'], 'allowedFamilies' => ['pdf', 'spreadsheet']]);
        self::assertTrue($driveTypePolicy->isRestricted());
        self::assertSame(['pdf', 'xlsx'], $driveTypePolicy->allowedExtensions);
        self::assertSame([DriveFileFamily::FAMILY_PDF, DriveFileFamily::FAMILY_SPREADSHEET], $driveTypePolicy->allowedFamilies);
        self::assertNull($driveTypePolicy->findFileNameRefusalReason('Report 2026.pdf'));
        self::assertNotNull($driveTypePolicy->findFileNameRefusalReason('logo.svg'));
        self::assertNotNull($driveTypePolicy->findFileNameRefusalReason('README'), 'no extension');
        $familyOnlyPolicy = $this->buildPolicy(['allowedFamilies' => ['IMAGE']]);
        self::assertNull($familyOnlyPolicy->findFileNameRefusalReason('photo.JPG'));
        self::assertNotNull($familyOnlyPolicy->findFileNameRefusalReason('budget.xlsx'));
    }

    public function testAnnouncedTypesAndSizesAreChecked(): void
    {
        $driveTypePolicy = $this->buildPolicy(['allowedContentTypes' => ['application/pdf'], 'maxFileSizeBytes' => 1000]);
        self::assertNull($driveTypePolicy->findAnnouncedContentTypeRefusalReason('application/pdf; charset=binary'));
        self::assertNull($driveTypePolicy->findAnnouncedContentTypeRefusalReason(null), 'nothing announced: the ingest decides');
        self::assertNull($driveTypePolicy->findAnnouncedContentTypeRefusalReason('application/octet-stream'));
        self::assertNotNull($driveTypePolicy->findAnnouncedContentTypeRefusalReason('text/html'));
        self::assertNull($driveTypePolicy->findSizeRefusalReason(1000));
        self::assertNotNull($driveTypePolicy->findSizeRefusalReason(1001));
    }

    public function testTheBytesMustFitTheName(): void
    {
        $driveTypePolicy = $this->buildPolicy(['allowedFamilies' => ['PDF', 'SPREADSHEET', 'DOCUMENT', 'IMAGE', 'TEXT', 'VECTOR']]);
        self::assertNull($driveTypePolicy->findContentRefusalReason('report.pdf', 'application/pdf', 'application/pdf'));
        self::assertNotNull($driveTypePolicy->findContentRefusalReason('report.pdf', 'application/pdf', 'image/png'), 'a picture named pdf');
        self::assertNull($driveTypePolicy->findContentRefusalReason('budget.xlsx', 'application/octet-stream', 'application/zip'), 'OOXML is a ZIP');
        self::assertNull($driveTypePolicy->findContentRefusalReason('codes.csv', 'text/csv', 'text/plain'), 'CSV is plain text');
        self::assertNull($driveTypePolicy->findContentRefusalReason('logo.svg', 'image/svg+xml', 'text/xml'), 'SVG is XML');
        self::assertNull($driveTypePolicy->findContentRefusalReason('notes.txt', 'text/plain', 'application/octet-stream'), 'libmagic cannot tell');
        self::assertNotNull($driveTypePolicy->findContentRefusalReason('photo.jpg', 'image/jpeg', 'application/x-dosexec'), 'a program is never taken');
        self::assertNotNull($driveTypePolicy->findContentRefusalReason('page.html', 'text/html', 'text/html'), 'the name is checked again');
        $lenientPolicy = $this->buildPolicy(['allowedFamilies' => ['PDF', 'IMAGE'], 'refusesContentMismatch' => false]);
        self::assertNull($lenientPolicy->findContentRefusalReason('report.pdf', 'application/pdf', 'image/png'));
    }

    public function testNamesAreMadeSafe(): void
    {
        self::assertSame('a_b_c.pdf', DriveTypePolicy::sanitizeFileName('a/b\\c.pdf'));
        self::assertSame('report.pdf', DriveTypePolicy::sanitizeFileName("  ..report\u{202E}.pdf . "));
        self::assertSame('x_y_.txt', DriveTypePolicy::sanitizeFileName('x:y?.txt'));
        self::assertSame('Grüße Liste.csv', DriveTypePolicy::sanitizeFileName("Gru\u{0308}ße   Liste.csv"));
        self::assertSame('file', DriveTypePolicy::sanitizeFileName(' . '));
        $longName = DriveTypePolicy::sanitizeFileName(str_repeat('a', 300) . '.xlsx');
        self::assertSame(255, mb_strlen($longName));
        self::assertStringEndsWith('.xlsx', $longName);
    }

    protected function buildPolicy(array $policySettings): DriveTypePolicy
    {
        return (new DriveTypePoliciesService())->buildTypePolicyFromSettings($policySettings);
    }
}
