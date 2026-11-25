<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Modules\CloudDrive\CloudDriveModule;

/** The module as a host without ddd-agents 0.5 sees it: the installation port of the agent package does not exist */
class ModuleWithoutAgentInstallationPort extends CloudDriveModule
{
    public const string MISSING_CLASS = 'DDD\\Tests\\CloudDrive\\Missing\\AgentFeatureTableConditionProvider';

    public static function getNamespacesRequiringClasses(): array
    {
        return array_map(
            static fn (string $requiredClassName): string => $requiredClassName === self::FEATURE_TABLE_CONDITION_PROVIDER_CLASS ? self::MISSING_CLASS : $requiredClassName,
            parent::getNamespacesRequiringClasses()
        );
    }
}
