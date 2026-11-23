<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Changes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One entry of a node's history: a change of the drive (CREATED, VERSIONED, MOVED …), an access (VIEWED, DOWNLOADED)
 * or an event of the application (its own types) — what happened, when, by whom and for whom, where, which version.
 */
class DriveChangeEventListItem extends ValueObject
{
    /** A change of the drive ({@see DriveChangeEvent}) */
    public const string SOURCE_CHANGE = 'CHANGE';

    /** An access or an application's event ({@see \DDD\Domain\CloudDrive\Entities\Activities\DriveActivityEvent}) */
    public const string SOURCE_ACTIVITY = 'ACTIVITY';

    /** @var int The id within its source (changes and activities count separately) */
    public int $id = 0;

    /** @var string SOURCE_CHANGE or SOURCE_ACTIVITY */
    public string $eventSource = self::SOURCE_CHANGE;

    /** @var string {@see DriveChangeEvent} EVENT_*, or an application's own type */
    public string $eventType = '';

    public ?DateTime $occurredAt = null;

    /** @var int|null null = the system (scan, maintenance) */
    public ?int $actorAccountId = null;

    /** @var string|null The actor's name as the reader's audience sees it */
    public ?string $actorName = null;

    /** @var int|null The account the actor acted for */
    public ?int $onBehalfOfAccountId = null;

    public ?string $onBehalfOfName = null;

    /** @var string|null The audience the actor acted as (INTERNAL, SHARED), when recorded */
    public ?string $audience = null;

    /** @var string|null The content variant of an access: INLINE (preview) or DOWNLOAD (original) */
    public ?string $contentVariant = null;

    /** @var string|null A short detail of an application's event */
    public ?string $detail = null;

    public ?string $path = null;

    public ?string $previousPath = null;

    public ?int $driveFileVersionId = null;

    /** @var bool Made by a working copy (an agent sandbox) */
    public bool $isFromWorkingCopy = false;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->eventSource . '_' . $this->id);
    }
}
