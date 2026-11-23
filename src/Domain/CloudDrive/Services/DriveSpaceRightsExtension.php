<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The host's rules for who may read, write and manage which drive spaces (registered by this tag, autoconfigured; any
 * number), composed by {@see DriveSpaceRightsExtensionsService} into the one rights query of the drive
 * ({@see \DDD\Domain\CloudDrive\Repo\DB\Spaces\DBDriveSpace::applyRightsToSpaceQuery()}). Every drive table (nodes,
 * versions, blobs, feed, working copies) and every right check of the services filters through it, so an extension
 * changes the drive's rights everywhere at once — a host never subclasses DriveSpace, DBDriveSpace or its model for
 * rights.
 *
 * Composition, per right (READ, WRITE, MANAGE of DBDriveSpace):
 * 1. {@see isCallerUnrestricted()}: one extension answering false restricts the caller to the granted spaces (also an
 *    admin); otherwise one answering true lets the caller hold the right on every space; otherwise the default
 *    applies: an admin account holds every right on every space, every other caller none.
 * 2. A restricted caller holds the right on the spaces matched by any {@see buildSpaceRightsCondition()} (OR); no
 *    condition = no space.
 *
 * Keep the hierarchy: a condition that grants WRITE must also be granted for READ, MANAGE only where WRITE is
 * (DriveSpaceRightsContractTestCase checks it). The audience rules (DriveSpacesService::isRightAllowedForAudience())
 * narrow the result further.
 */
#[AutoconfigureTag(DriveSpaceRightsExtension::TAG)]
interface DriveSpaceRightsExtension
{
    public const string TAG = 'clouddrive.space_rights_extension';

    /**
     * Whether the caller holds `$right` on every space (true), only on the spaces granted by conditions (false, also
     * for an admin account), or whether this extension has no opinion (null).
     */
    public function isCallerUnrestricted(?Account $authAccount, string $right): ?bool;

    /**
     * A DQL condition on the space rows of `$spaceAlias` under which the caller holds `$right` (e.g.
     * "{$spaceAlias}.spaceKind = :p_kind AND {$spaceAlias}.id IN (SELECT …)"); null when it grants nothing.
     *
     * - Parameters set on `$queryBuilder` and aliases of subqueries start with `$parameterPrefix` (unique per
     *   extension and query).
     * - No joins on `$queryBuilder`: the condition is OR-composed with the conditions of other extensions; reach other
     *   tables through subqueries (model class names from `Entity::getRepoClassInstance()::BASE_ORM_MODEL`).
     */
    public function buildSpaceRightsCondition(
        DoctrineQueryBuilder $queryBuilder,
        string $spaceAlias,
        string $right,
        ?Account $authAccount,
        string $parameterPrefix
    ): ?string;
}
