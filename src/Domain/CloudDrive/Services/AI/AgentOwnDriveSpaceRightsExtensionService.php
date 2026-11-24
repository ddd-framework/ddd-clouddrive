<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Services\DriveSpaceRightsExtension;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\Service;

/**
 * The own drive of an agent space belongs to the agent space's account: that account holds READ, WRITE and MANAGE on
 * the spaces of kind `agentSpace` it owns (`DriveSpace::$accountId`, set by
 * {@see DriveSpaceMountResolverService::findOrCreateOwnDriveSpace()}). Admins hold every right by the drive's default.
 *
 * A host with other rules for own drives overrides this service at the same path ({@see buildSpaceRightsCondition()}
 * returning null switches it off) and grants them in its own DriveSpaceRightsExtension.
 */
class AgentOwnDriveSpaceRightsExtensionService extends Service implements DriveSpaceRightsExtension
{
    public function isCallerUnrestricted(?Account $authAccount, string $right): ?bool
    {
        return null;
    }

    public function buildSpaceRightsCondition(
        DoctrineQueryBuilder $queryBuilder,
        string $spaceAlias,
        string $right,
        ?Account $authAccount,
        string $parameterPrefix
    ): ?string {
        if ($authAccount === null || !isset($authAccount->id) || (int)$authAccount->id <= 0) {
            return null;
        }
        $queryBuilder->setParameter($parameterPrefix . 'ownDriveKind', DriveSpaceMountResolverService::OWN_DRIVE_SPACE_KIND);
        $queryBuilder->setParameter($parameterPrefix . 'ownDriveAccountId', (int)$authAccount->id);
        return "({$spaceAlias}.spaceKind = :{$parameterPrefix}ownDriveKind AND {$spaceAlias}.accountId = :{$parameterPrefix}ownDriveAccountId)";
    }
}
