<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\TypePolicies\DriveTypePolicy;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * The type policy of a space for an audience ({@see DriveTypePolicy}): from `CloudDrive.typePolicies` by space kind
 * (`default` when the kind has no entry), the SHARED audience with the kind's `shared` block on top. A host whose
 * allow-list comes from elsewhere (an allow-list per partner, a setting of the space's anchor) overrides
 * {@see buildTypePolicyForSpace()} at the same path.
 */
class DriveTypePoliciesService extends Service
{
    /** @var string The block of a kind's policy that applies to the SHARED audience */
    public const string SHARED_AUDIENCE_BLOCK = 'shared';

    public function buildTypePolicyForSpace(DriveSpace $driveSpace, string $audience): DriveTypePolicy
    {
        $typePolicies = Config::get('CloudDrive.typePolicies');
        $typePolicies = is_array($typePolicies) ? $typePolicies : [];
        $kindSettings = $typePolicies[$driveSpace->spaceKind ?? DriveSpace::DEFAULT_SPACE_KIND] ?? $typePolicies[DriveSpace::DEFAULT_SPACE_KIND] ?? [];
        $kindSettings = is_array($kindSettings) ? $kindSettings : [];
        $policySettings = $kindSettings;
        unset($policySettings[self::SHARED_AUDIENCE_BLOCK]);
        if ($audience === DriveSpace::AUDIENCE_SHARED && is_array($kindSettings[self::SHARED_AUDIENCE_BLOCK] ?? null)) {
            $policySettings = array_replace($policySettings, $kindSettings[self::SHARED_AUDIENCE_BLOCK]);
        }
        return $this->buildTypePolicyFromSettings($policySettings);
    }

    /** A policy from one block of settings (`allowedFamilies`, `allowedExtensions`, `allowedContentTypes`, `maxFileSizeBytes`, `refusesContentMismatch`) */
    public function buildTypePolicyFromSettings(array $policySettings): DriveTypePolicy
    {
        $driveTypePolicy = new DriveTypePolicy();
        $driveTypePolicy->allowedFamilies = array_values(array_map('strtoupper', array_filter((array)($policySettings['allowedFamilies'] ?? []), 'is_string')));
        $allowedExtensions = [];
        foreach ((array)($policySettings['allowedExtensions'] ?? []) as $extension) {
            if (is_string($extension) && trim($extension, '. ') !== '') {
                $allowedExtensions[] = strtolower(trim($extension, '. '));
            }
        }
        $driveTypePolicy->allowedExtensions = $allowedExtensions;
        $driveTypePolicy->allowedContentTypes = array_values(array_map('strtolower', array_filter((array)($policySettings['allowedContentTypes'] ?? []), 'is_string')));
        $maxFileSizeBytes = $policySettings['maxFileSizeBytes'] ?? null;
        $driveTypePolicy->maxFileSizeBytes = is_numeric($maxFileSizeBytes) ? (int)$maxFileSizeBytes : null;
        $driveTypePolicy->refusesContentMismatch = (bool)($policySettings['refusesContentMismatch'] ?? true);
        return $driveTypePolicy;
    }
}
