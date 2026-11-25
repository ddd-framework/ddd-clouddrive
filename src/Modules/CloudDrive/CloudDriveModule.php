<?php

declare(strict_types=1);

namespace DDD\Modules\CloudDrive;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * The cloud drive module: spaces whose rights the host's space repository decides, folders and versioned files whose
 * bytes live in object storage (store `clouddrive`), working copies for agent sandboxes and, beside
 * `mgamadeus/ddd-agents`, the drive mount and tools of the agent (and, beside the agent's browser as well, the
 * browser's file sink).
 */
class CloudDriveModule extends DDDModule
{
    /** @var string A class of ddd-agents: the agent part of the module registers only beside it */
    public const string AGENTS_PACKAGE_CLASS = 'DDD\\Domain\\AI\\Services\\Drives\\AgentDriveStore';

    /** @var string A class of ddd-agents ≥ 0.1.29: the eval isolation registers only beside it */
    public const string AGENTS_EVAL_PARTICIPANT_CLASS = 'DDD\\Domain\\AI\\Services\\Eval\\AgentEvalWorkspaceParticipant';

    /** @var string The file sink port of ddd-agent-browser ≥ 0.2.0: the drive's browser sink registers only beside it */
    public const string BROWSER_FILE_SINK_CLASS = 'DDD\\Domain\\AI\\Services\\Browser\\BrowserFileSink';

    /**
     * @var string The table condition port of ddd-agents (>= 0.5.0, which also has the installation check port): the
     *     drive's installation check and table conditions register only beside it
     */
    public const string FEATURE_TABLE_CONDITION_PROVIDER_CLASS = 'DDD\\Domain\\AI\\Services\\Installation\\AgentFeatureTableConditionProvider';

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
            // a class below Eval\\ needs both classes: the eval participant of ddd-agents ≥ 0.1.29 and the drive store
            'DDD\\Domain\\CloudDrive\\Services\\AI\\Eval\\' => self::AGENTS_EVAL_PARTICIPANT_CLASS,
            // a class below Browser\\ needs the browser's file sink port and the drive store (all matching prefixes apply)
            'DDD\\Domain\\CloudDrive\\Services\\AI\\Browser\\' => self::BROWSER_FILE_SINK_CLASS,
            'DDD\\Domain\\CloudDrive\\Services\\AI\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Domain\\CloudDrive\\Entities\\AI\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Domain\\CloudDrive\\Repo\\DB\\AI\\' => self::AGENTS_PACKAGE_CLASS,
            'DDD\\Presentation\\Mcp\\Client\\CloudDrive\\' => self::AGENTS_PACKAGE_CLASS,
            // app:agent:check-installation of ddd-agents >= 0.5.0: the rendition check and the drive's table conditions
            'DDD\\Domain\\CloudDrive\\Services\\Installation\\' => self::FEATURE_TABLE_CONDITION_PROVIDER_CLASS,
        ];
    }
}
