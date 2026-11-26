<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveChangesService;

/** Records the host events of the import tests; the caller is account 5 acting for itself */
class FileImportDriveChangesService extends DriveChangesService
{
    /** @var string[] `<nodeId> <eventType> <detail>` per recorded event */
    public array $recordedHostEvents = [];

    public function __construct()
    {
    }

    public function findActingAccountIdOfCurrentCaller(): ?int
    {
        return 5;
    }

    public function findOnBehalfOfAccountIdOfCurrentCaller(): ?int
    {
        return null;
    }

    public function recordHostEvent(DriveNode $driveNode, string $eventType, ?string $detail = null, ?int $actorAccountId = null, ?int $onBehalfOfAccountId = null): ?DriveActivityEvent
    {
        $this->recordedHostEvents[] = $driveNode->id . ' ' . $eventType . ' ' . $detail;
        return null;
    }
}
