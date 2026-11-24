<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services\AI\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalScratchFiles;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Services\Eval\AgentEvalWorkspaceParticipant;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveMaintenanceService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Isolates an eval case's drive: the case's workspace key gets a throwaway own drive space (`scratchKey`), seeded
 * with the case's `scratchFiles`; while the case runs the agent's own drive is that space and every further mount is
 * read-only; at the case's end the space is erased (kept with `--keepWorkspace`).
 */
class CloudDriveEvalWorkspaceParticipantService extends Service implements AgentEvalWorkspaceParticipant
{
    public const string SCRATCH_SPACE_KIND = 'evalScratch';

    public function prepareWorkspaceOfCase(
        AgentEvalScope $agentEvalScope,
        string $workspaceKey,
        AgentEvalScratchFiles $agentEvalScratchFiles,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        $driveSpacesService = $this->getDriveSpacesService();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveSpace = $driveSpacesService->findSpaceByScratchKey($workspaceKey);
            if ($driveSpace === null) {
                $driveSpace = DriveSpace::newInstance();
                $driveSpace->spaceKind = self::SCRATCH_SPACE_KIND;
                $driveSpace->title = 'eval ' . $workspaceKey;
                $driveSpace->audience = DriveSpace::AUDIENCE_INTERNAL;
                $driveSpace->scratchKey = $workspaceKey;
                $driveSpace = $driveSpacesService->createSpace($driveSpace);
            }
            $preparedLines = [sprintf('drive: scratch space %d for %s', (int)$driveSpace->id, $workspaceKey)];
            foreach ($agentEvalScratchFiles->getElements() as $agentEvalScratchFile) {
                try {
                    $this->getDriveVersionsService()->writeFileContent($driveSpace, $agentEvalScratchFile->path, $agentEvalScratchFile->content, null, 'eval seed', DriveNode::ORIGIN_MIGRATED);
                    $preparedLines[] = 'drive: seeded ' . $agentEvalScratchFile->path;
                } catch (Throwable $throwable) {
                    $preparedLines[] = '[ERROR] drive: ' . $agentEvalScratchFile->path . ': ' . $throwable->getMessage();
                }
            }
            return $preparedLines;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function cleanUpWorkspaceOfCase(string $workspaceKey, AIConversationProgressReporterInterface $progressReporter): array
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $driveSpace = $this->getDriveSpacesService()->findSpaceByScratchKey($workspaceKey);
            if ($driveSpace === null) {
                return [];
            }
            /** @var DriveMaintenanceService $driveMaintenanceService */
            $driveMaintenanceService = DDDService::instance()->getService(DriveMaintenanceService::class);
            return array_map([$this, 'prefixDriveLine'], $driveMaintenanceService->eraseSpace($driveSpace));
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function prefixDriveLine(string $line): string
    {
        return 'drive: ' . $line;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        return $driveVersionsService;
    }
}
