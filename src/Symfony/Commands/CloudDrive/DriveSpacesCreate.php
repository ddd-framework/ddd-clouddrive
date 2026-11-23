<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\CloudDrive;

use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
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
 * Creates a space without anchor (a library) or of a given kind; a host's anchored spaces come from its resolver.
 */
#[AsCommand(name: 'app:drive:spaces:create', description: 'Creates a drive space (by default the library: kind library, internal audience)')]
class DriveSpacesCreate extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The space\'s name for people', 'Library')
            ->addOption('spaceKind', null, InputOption::VALUE_REQUIRED, 'The kind (selects CloudDrive.spaceKinds)', 'library')
            ->addOption('audience', null, InputOption::VALUE_REQUIRED, 'INTERNAL or SHARED (fixed at creation)', DriveSpace::AUDIENCE_INTERNAL)
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Show the space, store nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAccount) {
            AuthService::instance()->setAccount($defaultAccount);
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $driveSpace = DriveSpace::newInstance();
        $driveSpace->title = (string)$input->getOption('title');
        $driveSpace->spaceKind = (string)$input->getOption('spaceKind');
        $driveSpace->audience = strtoupper((string)$input->getOption('audience'));
        if ($input->getOption('dryRun')) {
            $symfonyStyle->text(sprintf('would create %s space "%s" (%s)', $driveSpace->spaceKind, $driveSpace->title, $driveSpace->audience));
            return Command::SUCCESS;
        }
        try {
            /** @var DriveSpacesService $driveSpacesService */
            $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
            $driveSpace = $driveSpacesService->createSpace($driveSpace);
            $symfonyStyle->success(sprintf('Created space %d "%s" (root folder %d).', (int)$driveSpace->id, $driveSpace->title, (int)$driveSpace->rootNodeId));
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }
}
