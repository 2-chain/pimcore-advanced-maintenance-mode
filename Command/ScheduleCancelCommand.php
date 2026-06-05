<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:schedule:cancel',
    description: 'Cancel a scheduled maintenance window by ID',
)]
final class ScheduleCancelCommand extends Command
{
    public function __construct(
        private readonly ScheduleStorage $storage,
        private readonly QueuedWindowStorageInterface $queue,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The window ID to cancel');
    }

    #[Override]
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasOption('ignore-maintenance-mode')) {
            $input->setOption('ignore-maintenance-mode', true);
        }
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        if ($this->storage->findById($id) === null) {
            $io->error(\sprintf('No scheduled window with id "%s" found.', $id));
            return Command::FAILURE;
        }

        $this->storage->remove($id);
        $this->queue->remove($id);

        $io->success(\sprintf('Cancelled scheduled window "%s".', $id));
        return Command::SUCCESS;
    }
}
