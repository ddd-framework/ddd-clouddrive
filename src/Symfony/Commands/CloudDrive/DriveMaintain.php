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
 * The drive's housekeeping in one run (a cron, e.g. hourly): expired system-folder files to the trash, the trash past
 * its retention, versions beyond their retention, and the blob sweep.
 */
#[AsCommand(
    name: 'app:drive:maintain',
    description: 'Trashes expired system-folder files, purges the trash and old versions past their retention, sweeps unreferenced blobs'
)]
class DriveMaintain extends Command
{
    protected function configure(): void
    {
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'List what would be done, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(3600);
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAccount) {
            AuthService::instance()->setAccount($defaultAccount);
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        try {
            /** @var DriveMaintenanceService $driveMaintenanceService */
            $driveMaintenanceService = DDDService::instance()->getService(DriveMaintenanceService::class);
            $resultLines = $driveMaintenanceService->runMaintenance((bool)$input->getOption('dryRun'));
            foreach ($resultLines as $resultLine) {
                $symfonyStyle->text($resultLine);
            }
            $symfonyStyle->success(sprintf('%d actions%s.', count($resultLines), $input->getOption('dryRun') ? ' (dry run)' : ''));
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }
}
