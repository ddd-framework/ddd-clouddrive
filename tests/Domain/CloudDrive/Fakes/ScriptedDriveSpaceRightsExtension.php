<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\CloudDrive\Services\DriveSpaceRightsExtension;
use DDD\Domain\Common\Entities\Accounts\Account;
use RuntimeException;

/** A rights extension whose answers a test sets per right */
class ScriptedDriveSpaceRightsExtension implements DriveSpaceRightsExtension
{
    /** @var array<string, bool|null> right => answer of isCallerUnrestricted() */
    public array $isUnrestrictedByRight = [];

    /** @var array<string, string> right => space kind granted for that right */
    public array $grantedSpaceKindByRight = [];

    public bool $throws = false;

    public function isCallerUnrestricted(?Account $authAccount, string $right): ?bool
    {
        if ($this->throws) {
            throw new RuntimeException('extension broke');
        }
        return $this->isUnrestrictedByRight[$right] ?? null;
    }

    public function buildSpaceRightsCondition(DoctrineQueryBuilder $queryBuilder, string $spaceAlias, string $right, ?Account $authAccount, string $parameterPrefix): ?string
    {
        if ($this->throws) {
            throw new RuntimeException('extension broke');
        }
        if (!isset($this->grantedSpaceKindByRight[$right])) {
            return null;
        }
        $queryBuilder->setParameter($parameterPrefix . 'spaceKind', $this->grantedSpaceKindByRight[$right]);
        return "{$spaceAlias}.spaceKind = :{$parameterPrefix}spaceKind";
    }
}
