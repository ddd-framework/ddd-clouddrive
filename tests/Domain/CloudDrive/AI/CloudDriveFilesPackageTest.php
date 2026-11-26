<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\AI;

use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Presentation\Mcp\Client\CloudDrive\CloudDriveMCPPackage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The skill `CloudDrive.Files` and its package `clouddrive.files`: names, tools, effects */
class CloudDriveFilesPackageTest extends TestCase
{
    public function testThePackageIdIsTheSkillNameInLowerCase(): void
    {
        self::assertSame('clouddrive.files', CloudDriveMCPPackage::$name);
        $moduleRoot = dirname(__DIR__, 4);
        self::assertFileExists($moduleRoot . '/config/app/AI/Skills/CloudDrive/Files.md');
        self::assertFileDoesNotExist($moduleRoot . '/config/app/AI/Skills/Assistant/Drive.md');
    }

    public function testEveryToolDeclaresItsEffect(): void
    {
        $effectsByToolName = [];
        foreach ((new ReflectionClass(CloudDriveMCPPackage::class))->getMethods() as $reflectionMethod) {
            if ($reflectionMethod->getAttributes(MCPTool::class) === []) {
                continue;
            }
            $effectAttributes = $reflectionMethod->getAttributes(MCPToolEffect::class);
            self::assertCount(1, $effectAttributes, $reflectionMethod->getName() . ' declares its effect');
            $effectsByToolName[$reflectionMethod->getName()] = $effectAttributes[0]->getArguments()[0] ?? null;
        }
        foreach (['getDriveSpace' => MCPToolEffect::READ, 'listDriveFileEvents' => MCPToolEffect::READ, 'createDriveFolder' => MCPToolEffect::WRITE,
            'rescanDriveFileVersion' => MCPToolEffect::WRITE, 'setDriveSpaceSettings' => MCPToolEffect::WRITE] as $toolName => $effect) {
            self::assertSame($effect, $effectsByToolName[$toolName] ?? null, $toolName);
        }
        foreach (array_keys($effectsByToolName) as $toolName) {
            self::assertStringContainsString($toolName, (string)CloudDriveMCPPackage::$description, 'the package description names ' . $toolName);
        }
    }
}
