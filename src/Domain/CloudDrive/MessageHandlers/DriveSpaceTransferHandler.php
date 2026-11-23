<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\CloudDrive\Services\DriveSpaceTransfersService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/** Runs a move between spaces ({@see DriveSpaceTransfersService::runTransferMessage()}) */
#[AsMessageHandler(fromTransport: DriveIngestVersionHandler::TRANSPORT)]
class DriveSpaceTransferHandler extends AppMessageHandler
{
    public function __invoke(DriveSpaceTransferMessage $message): void
    {
        set_time_limit(1800);
        DDDService::instance()->raiseMemoryLimitToAtLeast('512M');
        $this->setAuthAccountFromMessage($message);
        $this->getLogger()->info("Transfer of drive nodes from space {$message->sourceDriveSpaceId} to space {$message->targetDriveSpaceId}");
        try {
            /** @var DriveSpaceTransfersService $driveSpaceTransfersService */
            $driveSpaceTransfersService = DDDService::instance()->getService(DriveSpaceTransfersService::class);
            $driveSpaceTransfersService->runTransferMessage($message);
        } catch (Throwable $throwable) {
            $this->logShortException($this->getLogger(), "Transfer of drive nodes to space {$message->targetDriveSpaceId}", $throwable);
        }
    }
}
