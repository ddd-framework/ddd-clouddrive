<?php

declare(strict_types=1);

namespace DDD\Modules\CloudDrive;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * The cloud drive module: spaces whose rights the host's space repository decides, folders and versioned files whose
 * bytes live in object storage (store `clouddrive`), working copies for agent sandboxes and, beside
 * `mgamadeus/ddd-agents`, the drive mount and tools of the agent.
 */
class CloudDriveModule extends DDDModule
{
    /** @var string A class of ddd-agents: the agent part of the module registers only beside it */
    public const string AGENTS_PACKAGE_CLASS = 'DDD\\Domain\\AI\\Services\\Drives\\AgentDriveStore';

    public static function getSourcePath(): string
    {
        return __DIR__ . '/../..';
    }

    public static function getConfigPath(): ?string
    {
        return __DIR__ . '/../../../config/app';
    }

    public static function getPublicServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\CloudDrive\\Services\\',
        ];
    }

    public static function getExcludePatterns(): array
    {
        return [...parent::getExcludePatterns(), 'Dtos', 'Modules', 'Testing'];
    }

    public static function getNamespacesRequiringClasses(): array
    {
        return [
            'DDD\\Domain\\CloudDrive\\Services\\Agents\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Domain\\CloudDrive\\Entities\\AI\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Presentation\\Mcp\\Client\\CloudDrive\\' => self::AGENTS_PACKAGE_CLASS,
        ];
    }
}
