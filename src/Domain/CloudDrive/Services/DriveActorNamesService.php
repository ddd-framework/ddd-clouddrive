<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The names people see for who did something in a drive (creator, uploader, who trashed, the actors of the history),
 * for the audience that reads them. Two override points (same-path subclass in the application):
 *
 * - {@see DriveNodesService::findAccountDisplayNames()}: plain names of accounts (default none: ids only);
 * - {@see buildActorDisplayName()}: the name one audience sees for an actor, e.g. the staff as the company's name for
 *   the SHARED audience, or "Anna for Partner X" when the actor acted for someone.
 */
class DriveActorNamesService extends Service
{
    /**
     * Names of accounts keyed by id, as the audience sees them ({@see buildActorDisplayName()} per account).
     *
     * @param int[] $accountIds
     * @return string[]
     */
    public function findActorDisplayNames(array $accountIds, string $audience): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($accountIds === []) {
            return [];
        }
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        $accountDisplayNames = $driveNodesService->findAccountDisplayNames($accountIds);
        $actorDisplayNames = [];
        foreach ($accountIds as $accountId) {
            $actorDisplayName = $this->buildActorDisplayName($accountId, null, $audience, $accountDisplayNames);
            if ($actorDisplayName !== null) {
                $actorDisplayNames[$accountId] = $actorDisplayName;
            }
        }
        return $actorDisplayNames;
    }

    /**
     * The name `$audience` sees for an actor who may have acted for another account. Default: the actor's plain name
     * ({@see DriveNodesService::findAccountDisplayNames()}); null for the system or an unknown account.
     *
     * @param string[] $accountDisplayNames plain names already loaded, keyed by account id (else loaded here)
     */
    public function buildActorDisplayName(?int $actorAccountId, ?int $onBehalfOfAccountId, string $audience, array $accountDisplayNames = []): ?string
    {
        if ($actorAccountId === null) {
            return null;
        }
        if (!array_key_exists($actorAccountId, $accountDisplayNames)) {
            /** @var DriveNodesService $driveNodesService */
            $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
            $accountDisplayNames += $driveNodesService->findAccountDisplayNames([$actorAccountId]);
        }
        $actorDisplayName = $accountDisplayNames[$actorAccountId] ?? null;
        return is_string($actorDisplayName) && $actorDisplayName !== '' ? $actorDisplayName : null;
    }
}
