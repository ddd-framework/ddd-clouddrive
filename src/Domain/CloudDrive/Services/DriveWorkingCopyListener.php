<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResolutions;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveCommitResult;
use DDD\Domain\CloudDrive\Entities\WorkingCopies\DriveWorkingCopy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The host side of a live sandbox on a working copy (registered by this tag, autoconfigured): the drive calls every
 * listener through {@see DriveWorkingCopyListenersService}.
 *
 * - {@see beforeWorkingCopyRead()}: before the drive reads the saved changes (status, commit, the commit before a copy
 *   or a card): flush what the live sandbox changed since its last save into the working copy (`stageChanges()`).
 * - {@see afterWorkingCopyCommitted()}: after a commit: move the sandbox's base to the committed versions, apply the
 *   resolutions (theirs drops the local file, both renames it).
 *
 * A listener that throws is logged and skipped; the drive goes on with what is saved.
 */
#[AutoconfigureTag(DriveWorkingCopyListener::TAG)]
interface DriveWorkingCopyListener
{
    public const string TAG = 'clouddrive.working_copy_listener';

    public function beforeWorkingCopyRead(DriveWorkingCopy $driveWorkingCopy): void;

    public function afterWorkingCopyCommitted(DriveWorkingCopy $driveWorkingCopy, DriveCommitResult $driveCommitResult, ?DriveCommitResolutions $driveCommitResolutions): void;
}
