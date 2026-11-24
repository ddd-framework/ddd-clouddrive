<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\AI;

use DDD\Domain\AI\Entities\Browser\Files\BrowserFileToStore;
use DDD\Domain\CloudDrive\Services\AI\Browser\CloudDriveBrowserFileSinkService;
use DDD\Modules\CloudDrive\CloudDriveModule;
use PHPUnit\Framework\TestCase;

/** The intake of the drive's browser file sink: type from the bytes, allow-list per kind, active content refused, names kept */
class CloudDriveBrowserFileSinkTest extends TestCase
{
    public const string PNG_BYTES_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testTheSinkRegistersOnlyBesideTheBrowserAndTheAgent(): void
    {
        $namespacesRequiringClasses = CloudDriveModule::getNamespacesRequiringClasses();
        $sinkClassName = CloudDriveBrowserFileSinkService::class;
        $requiredClassNames = [];
        foreach ($namespacesRequiringClasses as $namespacePrefix => $requiredClassName) {
            if (str_starts_with($sinkClassName, $namespacePrefix)) {
                $requiredClassNames[] = $requiredClassName;
            }
        }
        self::assertContains(CloudDriveModule::BROWSER_FILE_SINK_CLASS, $requiredClassNames);
        self::assertContains(CloudDriveModule::AGENTS_PACKAGE_CLASS, $requiredClassNames);
        self::assertSame('DDD\\Domain\\AI\\Services\\Browser\\BrowserFileSink', CloudDriveModule::BROWSER_FILE_SINK_CLASS);
    }

    public function testATokenFileThatIsJsonIsTakenUnderItsFixedName(): void
    {
        $browserFileSinkService = new CloudDriveBrowserFileSinkService();
        $browserFileToStore = $this->createFile('design-tokens', 'tokens.txt', '{"colors":{"primary":"#123456"}}');
        $contentType = $browserFileSinkService->detectContentType($browserFileToStore);
        self::assertSame('application/json', $contentType);
        self::assertNull($browserFileSinkService->findRefusalReason($browserFileToStore, $contentType));
        self::assertSame('web/example.com/design-tokens.json', $browserFileSinkService->buildDrivePath($browserFileToStore, $contentType));
    }

    public function testALogoKeepsTheRenderersNameAndAnUnknownKindIsRefused(): void
    {
        $browserFileSinkService = new CloudDriveBrowserFileSinkService();
        $logoFile = $this->createFile('logo', 'logo.png', (string)base64_decode(self::PNG_BYTES_BASE64));
        $contentType = $browserFileSinkService->detectContentType($logoFile);
        self::assertSame('image/png', $contentType);
        self::assertNull($browserFileSinkService->findRefusalReason($logoFile, $contentType));
        self::assertSame('web/example.com/logo.png', $browserFileSinkService->buildDrivePath($logoFile, $contentType));

        $unknownKindFile = $this->createFile('downloads', 'logo.png', (string)base64_decode(self::PNG_BYTES_BASE64));
        self::assertStringContainsString('not taken', (string)$browserFileSinkService->findRefusalReason($unknownKindFile, 'image/png'));
    }

    public function testANameThatLiesAboutTheBytesOrIsNoPlainNameIsRefused(): void
    {
        $browserFileSinkService = new CloudDriveBrowserFileSinkService();
        $pngBytes = (string)base64_decode(self::PNG_BYTES_BASE64);
        self::assertStringContainsString('the name says .svg', (string)$browserFileSinkService->findRefusalReason($this->createFile('logo', 'logo.svg', $pngBytes), 'image/png'));
        self::assertSame('the file name is not a plain name', $browserFileSinkService->findRefusalReason($this->createFile('logo', '../logo.png', $pngBytes), 'image/png'));
        self::assertSame('web/example.com/logo-original.png', $browserFileSinkService->buildDrivePath($this->createFile('logo', 'logo-original', $pngBytes), 'image/png'), 'a name without an extension gets the one of its type');
    }

    public function testActiveContentIsNeverStored(): void
    {
        $browserFileSinkService = new CloudDriveBrowserFileSinkService();
        self::assertTrue($browserFileSinkService->isActiveContentType('application/pdf'));
        self::assertTrue($browserFileSinkService->isActiveContentType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        self::assertFalse($browserFileSinkService->isActiveContentType('image/png'));
        $pdfFile = $this->createFile('logo', 'logo.png', '%PDF-1.7 fake');
        self::assertStringContainsString('executable or active content', (string)$browserFileSinkService->findRefusalReason($pdfFile, 'application/pdf'));

        $scriptedSvg = $this->createFile('logo', 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        self::assertSame('the SVG carries scripts or other active content', $browserFileSinkService->findRefusalReason($scriptedSvg, 'image/svg+xml'));
        $handlerSvg = $this->createFile('logo', 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"></svg>');
        self::assertSame('the SVG carries scripts or other active content', $browserFileSinkService->findRefusalReason($handlerSvg, 'image/svg+xml'));
        $bindingCss = $this->createFile('stylesheets', 'stylesheet-01-main.css', 'body { behavior: url(x.htc) }');
        self::assertSame('the stylesheet carries script URLs, expressions or bindings', $browserFileSinkService->findRefusalReason($bindingCss, 'text/css'));
    }

    public function testAnSvgWithoutASanitizerIsNotStored(): void
    {
        $browserFileSinkService = new CloudDriveBrowserFileSinkService();
        $plainSvg = $this->createFile('logo', 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
        self::assertNull($browserFileSinkService->findRefusalReason($plainSvg, 'image/svg+xml'));
        self::assertNull($browserFileSinkService->buildStoredBytes($plainSvg, 'image/svg+xml'), 'no DriveSvgSanitizer registered: the SVG is refused, never stored raw');
        self::assertSame('body{}', $browserFileSinkService->buildStoredBytes($this->createFile('stylesheets', 'a.css', 'body{}'), 'text/css'));
    }

    public function testTheFolderComesFromThePageWhenTheTargetFolderIsNotASiteFolder(): void
    {
        $browserFileSinkService = new CloudDriveBrowserFileSinkService();
        $browserFileToStore = $this->createFile('screenshots', 'screenshot-viewport.png', 'x');
        $browserFileToStore->targetFolder = '../etc';
        $browserFileToStore->pageUrl = 'https://www.Shop.example.org/about';
        $browserFileToStore->sourceUrl = null;
        self::assertSame('web/shop.example.org', $browserFileSinkService->buildTargetFolder($browserFileToStore));
        self::assertStringStartsWith('Browser (screenshots) from https://www.Shop.example.org/about; written by a third-party web page', $browserFileSinkService->buildVersionComment($browserFileToStore));
    }

    protected function createFile(string $kind, string $fileName, string $bytes): BrowserFileToStore
    {
        $browserFileToStore = new BrowserFileToStore();
        $browserFileToStore->kind = $kind;
        $browserFileToStore->fileName = $fileName;
        $browserFileToStore->targetFolder = 'web/example.com';
        $browserFileToStore->sourceUrl = 'https://example.com/assets/' . $fileName;
        $browserFileToStore->setBytes($bytes);
        return $browserFileToStore;
    }
}
