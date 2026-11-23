<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Merges;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The outcome of a three-way merge of one file: MERGED (the changes did not overlap; `mergedText` holds both),
 * CONFLICT (overlapping changes: nothing is merged, `conflictText` shows both sides with markers for the decision)
 * or NOT_MERGEABLE (binary or too large: decided by the versions' facts only).
 */
class DriveTextMergeResult extends ValueObject
{
    public const string OUTCOME_MERGED = 'MERGED';
    public const string OUTCOME_CONFLICT = 'CONFLICT';
    public const string OUTCOME_NOT_MERGEABLE = 'NOT_MERGEABLE';

    public string $outcome = self::OUTCOME_CONFLICT;

    public ?string $mergedText = null;

    /** @var string|null CONFLICT: the merge with `<<<<<<< mine` / `>>>>>>> theirs` markers, for a short diff only */
    public ?string $conflictText = null;

    public int $conflictCount = 0;

    public function isMerged(): bool
    {
        return $this->outcome === self::OUTCOME_MERGED;
    }
}
