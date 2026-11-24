<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\CloudDrive;

use DDD\Domain\CloudDrive\Services\DriveMaintenanceService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Gives blobs stored without a telling content type (missing or application/octet-stream) the type their first
 * bytes name, and the versions and files on them too — so a PDF or a text written before the bytes decided the type
 * opens inline. Run it with --dryRun first; repeat while it reports more.
 */
#[AsCommand(
    name: 'app:drive:backfill-content-types',
    description: 'Reads the first bytes of blobs stored without a telling content type and stores the type they name (PDF, text, office, …) on the blob, its versions and files'
)]
class DriveBackfillContentTypes extends Command
{
    protected function configure(): void
    {
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'Read and count, change nothing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Blobs read in this run', '1000')
            ->addOption('driveSpaceId', null, InputOption::VALUE_REQUIRED, 'One space only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(3600);
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAccount) {
            AuthService::instance()->setAccount($defaultAccount);
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $isDryRun = (bool)$input->getOption('dryRun');
        $limit = (int)$input->getOption('limit');
        if ($limit < 1) {
            $symfonyStyle->error('--limit must be at least 1.');
            return Command::INVALID;
        }
        $driveSpaceId = $input->getOption('driveSpaceId') !== null ? (int)$input->getOption('driveSpaceId') : null;
        try {
            /** @var DriveMaintenanceService $driveMaintenanceService */
            $driveMaintenanceService = DDDService::instance()->getService(DriveMaintenanceService::class);
            $driveContentTypeBackfillResult = $driveMaintenanceService->backfillContentTypes($limit, $driveSpaceId, $isDryRun);
            $tableRows = [];
            foreach ($driveContentTypeBackfillResult->detectedContentTypes->getElements() as $driveContentTypeCount) {
                $tableRows[] = [$driveContentTypeCount->contentType, $driveContentTypeCount->blobCount];
            }
            if ($tableRows !== []) {
                $symfonyStyle->table(['Detected type', 'Blobs'], $tableRows);
            }
            $symfonyStyle->text(sprintf(
                'checked %d, %s %d, unchanged (bytes name no type) %d, unreadable %d',
                $driveContentTypeBackfillResult->checkedBlobCount,
                $isDryRun ? 'would update' : 'updated',
                $driveContentTypeBackfillResult->updatedBlobCount,
                $driveContentTypeBackfillResult->unchangedBlobCount,
                $driveContentTypeBackfillResult->unreadableBlobCount
            ));
            if ($driveContentTypeBackfillResult->hasMore) {
                $symfonyStyle->note('More blobs with an unknown type remain: run again.');
            }
            $symfonyStyle->success($isDryRun ? 'Dry run: nothing was changed.' : 'Content types stored.');
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }
}
