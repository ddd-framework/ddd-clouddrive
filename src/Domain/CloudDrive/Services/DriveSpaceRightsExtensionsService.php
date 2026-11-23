<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * Composes the host's {@see DriveSpaceRightsExtension}s into the drive's rights query. Fails closed: an extension that
 * throws grants nothing and restricts the caller.
 */
class DriveSpaceRightsExtensionsService extends Service
{
    /** @var iterable<DriveSpaceRightsExtension> */
    protected iterable $extensions = [];

    /** @param iterable<DriveSpaceRightsExtension> $extensions */
    #[Required]
    public function setExtensions(
        #[AutowireIterator(DriveSpaceRightsExtension::TAG)]
        iterable $extensions
    ): void {
        $this->extensions = $extensions;
    }

    /**
     * Whether the caller holds `$right` on every space: false as soon as one extension restricts (or throws), else true
     * when one extension says so, else `$isUnrestrictedByDefault` (an admin account).
     */
    public function isCallerUnrestricted(?Account $authAccount, string $right, bool $isUnrestrictedByDefault): bool
    {
        $isUnrestrictedByAnExtension = false;
        foreach ($this->extensions as $driveSpaceRightsExtension) {
            try {
                $isUnrestricted = $driveSpaceRightsExtension->isCallerUnrestricted($authAccount, $right);
            } catch (Throwable $throwable) {
                $this->logExtensionFailure($driveSpaceRightsExtension, 'isCallerUnrestricted', $throwable);
                return false;
            }
            if ($isUnrestricted === false) {
                return false;
            }
            if ($isUnrestricted === true) {
                $isUnrestrictedByAnExtension = true;
            }
        }
        return $isUnrestrictedByAnExtension || $isUnrestrictedByDefault;
    }

    /**
     * Restricts the space rows of `$spaceAlias` to the spaces the extensions grant `$right` on (OR); with no grant
     * the restriction matches no space. Always adds a condition, so always true (the rights contract of the
     * repositories: conditions added = true).
     */
    public function applyGrantedSpacesCondition(DoctrineQueryBuilder &$queryBuilder, string $spaceAlias, string $right, ?Account $authAccount): bool
    {
        $grantConditions = [];
        $extensionIndex = 0;
        foreach ($this->extensions as $driveSpaceRightsExtension) {
            $parameterPrefix = $spaceAlias . '_rightsExtension' . $extensionIndex . '_';
            $extensionIndex++;
            try {
                $grantCondition = $driveSpaceRightsExtension->buildSpaceRightsCondition($queryBuilder, $spaceAlias, $right, $authAccount, $parameterPrefix);
            } catch (Throwable $throwable) {
                $this->logExtensionFailure($driveSpaceRightsExtension, 'buildSpaceRightsCondition', $throwable);
                continue;
            }
            if ($grantCondition !== null && trim($grantCondition) !== '') {
                $grantConditions[] = '(' . $grantCondition . ')';
            }
        }
        $queryBuilder->andWhere($grantConditions === [] ? "{$spaceAlias}.id IS NULL" : '(' . implode(' OR ', $grantConditions) . ')');
        return true;
    }

    protected function logExtensionFailure(DriveSpaceRightsExtension $driveSpaceRightsExtension, string $hook, Throwable $throwable): void
    {
        DDDService::instance()->getLogger()->warning('[CloudDrive] space rights extension failed (treated as no grant)', [
            'extension' => $driveSpaceRightsExtension::class,
            'hook' => $hook,
            'reason' => $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 500),
        ]);
    }
}
