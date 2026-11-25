<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\CloudDrive\Entities\AI\Conversations\SignalSources\CloudDriveConversationSignalSource;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileWriteResult;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Notes in an agent conversation what a person did to a drive file beside it (the file is open in the chat's canvas):
 * a signal the agent's next turn reads — "the owner saved version 4 of X (was 3)". The note is built from the write's
 * own facts on the server; the client only names the conversation, and only a conversation its account may read
 * gets a note. Needs mgamadeus/ddd-agents; callers check {@see isAvailable()} first.
 */
class DriveAgentConversationNotesService extends Service
{
    /** The source label of the file's name and path in the note (they are written by people, also by third parties) */
    public const string UNTRUSTED_SOURCE = 'drive file name';

    /** Whether the agent package is installed (the drive runs without it) */
    public static function isAvailable(): bool
    {
        return class_exists(AIConversationTurnSignalsService::class);
    }

    /**
     * Notes a version a person wrote in the conversation `$agentConversationId`: nothing for a write that changed
     * nothing (a conflict, the same content) and nothing for a conversation the caller may not read.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function noteVersionWrittenByPerson(int $agentConversationId, DriveFileWriteResult $driveFileWriteResult): void
    {
        $driveNode = $driveFileWriteResult->driveNode;
        $driveFileVersion = $driveFileWriteResult->driveFileVersion;
        if ($agentConversationId <= 0 || $driveFileWriteResult->isConflict || $driveFileWriteResult->isUnchanged || $driveNode === null || $driveFileVersion === null) {
            return;
        }
        $aIConversation = $this->findConversationOfCaller($agentConversationId);
        if ($aIConversation === null) {
            return;
        }
        $signalText = $this->buildVersionWrittenNote($driveNode, (int)$driveFileVersion->versionNumber, $driveFileWriteResult->isNewFile);
        $this->writeNote($aIConversation, $signalText);
    }

    /**
     * Writes the note as a signal of {@see CloudDriveConversationSignalSource::DRIVE_FILE_CHANGED_BY_PERSON}: stored
     * for the next turn, it starts none.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    protected function writeNote(AIConversation $aIConversation, string $signalText): void
    {
        /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
        $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
        $aIConversationTurnSignalsService->injectSignal($aIConversation, $signalText, CloudDriveConversationSignalSource::DRIVE_FILE_CHANGED_BY_PERSON->value);
    }

    /**
     * The note's text: the file by reference, name and path (marked as written by people), the version before and the
     * new one, and what follows for the agent.
     */
    public function buildVersionWrittenNote(DriveNode $driveNode, int $versionNumber, bool $isNewFile): string
    {
        $markedPath = ToolResultUntrustedSegments::wrapSingleLine(self::UNTRUSTED_SOURCE, $this->buildPathOfNode($driveNode));
        $reference = $driveNode->buildReference();
        if ($isNewFile || $versionNumber <= 1) {
            return sprintf(
                'The owner saved a new drive file in the canvas beside this chat: %s (%s), version %d. It did not exist when you last looked.',
                $markedPath,
                $reference,
                max(1, $versionNumber)
            );
        }
        return sprintf(
            'The owner edited a drive file in the canvas beside this chat and saved it: %s (%s), version %d → %d. '
            . 'What you read of this file before is outdated: read it again before you quote it, rely on it or write to it.',
            $markedPath,
            $reference,
            $versionNumber - 1,
            $versionNumber
        );
    }

    /** The file's path in its space (`/Reports/plan.md`) */
    protected function buildPathOfNode(DriveNode $driveNode): string
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        return $driveNodesService->buildPath($driveNode);
    }

    /**
     * The conversation under the caller's read rights; null when it does not exist, is not the caller's or is hidden
     * from customers (the id came from a client: it never names a conversation the caller could not open).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    protected function findConversationOfCaller(int $agentConversationId): ?AIConversation
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        try {
            return $aIConversationsService->findVisibleToCustomer($agentConversationId);
        } catch (NotFoundException) {
            return null;
        }
    }
}
