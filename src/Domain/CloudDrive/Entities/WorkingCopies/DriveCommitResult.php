<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\WorkingCopies;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a commit did: the committed files (with their new version), the conflicts it left uncommitted and the paths
 * it refused (with the reason). Nothing is ever overwritten silently.
 */
class DriveCommitResult extends ValueObject
{
    public ?DriveWorkingCopyFiles $committedFiles = null;

    public ?DriveWorkingCopyFiles $conflictedFiles = null;

    /** @var string[] path: reason */
    public array $refusedLines = [];

    public function buildSummaryLine(): string
    {
        $committedParts = [];
        foreach (($this->committedFiles?->getElements() ?? []) as $committedFile) {
            $committedParts[] = $committedFile->path . ' ' . match ($committedFile->changeKind) {
                DriveWorkingCopyEntry::CHANGE_ADDED => 'new',
                DriveWorkingCopyEntry::CHANGE_DELETED => 'trashed',
                DriveWorkingCopyEntry::CHANGE_MOVED => 'moved',
                default => 'v' . $committedFile->versionNumber,
            };
        }
        $summaryLines = [$committedParts === [] ? 'nothing committed' : sprintf('committed %d files: %s', count($committedParts), implode(' · ', $committedParts))];
        foreach (($this->conflictedFiles?->getElements() ?? []) as $conflictedFile) {
            $summaryLines[] = 'conflict: ' . $conflictedFile->path . ' — ' . $conflictedFile->conflictReason . ' (resolve with mine, theirs or both)';
        }
        foreach ($this->refusedLines as $refusedLine) {
            $summaryLines[] = 'refused: ' . $refusedLine;
        }
        return implode("\n", $summaryLines);
    }
}
