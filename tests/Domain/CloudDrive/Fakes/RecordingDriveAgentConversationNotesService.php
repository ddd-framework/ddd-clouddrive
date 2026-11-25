<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Services\AI\DriveAgentConversationNotesService;

/** The notes service without a database: conversations the test names are readable, notes are recorded */
class RecordingDriveAgentConversationNotesService extends DriveAgentConversationNotesService
{
    /** @var int[] The conversations the caller may read */
    public array $readableConversationIds = [];

    /** @var int[] Every conversation looked up */
    public array $lookedUpConversationIds = [];

    /** @var string[] The notes written, in order */
    public array $writtenNotes = [];

    public function __construct()
    {
    }

    protected function buildPathOfNode(DriveNode $driveNode): string
    {
        return '/Reports/' . $driveNode->name;
    }

    protected function findConversationOfCaller(int $agentConversationId): ?AIConversation
    {
        $this->lookedUpConversationIds[] = $agentConversationId;
        if (!in_array($agentConversationId, $this->readableConversationIds, true)) {
            return null;
        }
        /** @var AIConversation $aIConversation */
        $aIConversation = (new \ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        return $aIConversation;
    }

    protected function writeNote(AIConversation $aIConversation, string $signalText): void
    {
        $this->writtenNotes[] = $signalText;
    }
}
