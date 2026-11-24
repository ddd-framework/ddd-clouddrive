<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\DriveNodesService;

/** Nodes of the transfer tests, in memory */
class TransferTestDriveNodesService extends DriveNodesService
{
    /** @var DriveNode[] Nodes by id */
    public array $nodesById = [];

    public function __construct()
    {
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->nodesById[(int)$entityId] ?? null;
    }
}
