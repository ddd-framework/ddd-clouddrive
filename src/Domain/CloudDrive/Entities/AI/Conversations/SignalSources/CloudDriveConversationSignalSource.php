<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\Conversations\SignalSources;

use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSourceDefaultAnswers;

/**
 * The cloud drive's signal sources: the stored
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::$signalSource} values the drive writes
 * into an agent conversation. Found by {@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources}
 * through the folder convention (`Domain/<Domain>/Entities/AI/Conversations/SignalSources/`).
 */
enum CloudDriveConversationSignalSource: string implements ConversationSignalSource
{
    use ConversationSignalSourceDefaultAnswers;

    /**
     * A PERSON wrote a version of a drive file beside the conversation (the file is open in the chat's canvas and was
     * edited there): the platform notes the file, the version before and the new one, so the agent's next turn knows
     * that what it read of the file is outdated. A NOTE, not work: it never starts a turn and owes no answer; written
     * by the server from the write's own facts ({@see \DDD\Domain\CloudDrive\Services\AI\DriveAgentConversationNotesService}),
     * never posted by a client.
     */
    case DRIVE_FILE_CHANGED_BY_PERSON = 'DRIVE_FILE_CHANGED_BY_PERSON';

    // --- basic questions ---

    /** A note the owed-turn walks see through: the next turn reads it, nothing answers it. */
    public function owesWork(): bool
    {
        return false;
    }

    /** The file changed although no tool of the conversation wrote it: a read of it before the note no longer holds. */
    public function invalidatesEarlierReads(): bool
    {
        return true;
    }
}
