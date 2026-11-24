<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\Rights;

/**
 * The account rights of the drive's agent part, named by the package (the host's rights class keeps only the host's
 * rule). A host gates the skill `CloudDrive.Files` with `USE_DRIVE` (`config/app/AI/skillRequirements.php`); ddd-agents
 * maps rights to roles by config once its default account-rights mapping exists. Until then the constant only names
 * the right: nothing in this package checks it.
 */
class CloudDriveAccountRights
{
    /** @var string Use the drive through the agent (the skill `CloudDrive.Files` and its tools) */
    public const string USE_DRIVE = 'useDrive';

    /** @var string[] Every right of this package */
    public const array RIGHTS = [self::USE_DRIVE];
}
