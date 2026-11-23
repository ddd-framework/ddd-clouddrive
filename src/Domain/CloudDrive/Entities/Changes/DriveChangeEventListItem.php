<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Changes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** One entry of a node's history: what happened, when, by whom, where, and which version */
class DriveChangeEventListItem extends ValueObject
{
    public int $id = 0;

    /** @var string {@see DriveChangeEvent} EVENT_* */
    public string $eventType = '';

    public ?DateTime $occurredAt = null;

    /** @var int|null null = the system (scan, maintenance) */
    public ?int $actorAccountId = null;

    public ?string $actorName = null;

    public ?string $path = null;

    public ?string $previousPath = null;

    public ?int $driveFileVersionId = null;

    /** @var bool Made by a working copy (an agent sandbox) */
    public bool $isFromWorkingCopy = false;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->id);
    }
}
