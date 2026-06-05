<?php
declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:pre-announce:cancel',
    description: 'Cancel a pending pre-announcement',
)]
final class PreAnnounceCancelCommand extends Command
{
    public function __construct(private readonly PreAnnounceStorage $storage)
    {
        parent::__construct();
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
        $data = $this->storage->load();
        if ($data === null) {
            $output->writeln('<error>No pre-announcement is currently set.</error>');
            return Command::FAILURE;
        }

        $this->storage->clear();
        $output->writeln('<info>Pre-announcement cancelled.</info>');
        $output->writeln('Was scheduled for: ' . $data->at->format('Y-m-d H:i:s') . ' UTC');

        return Command::SUCCESS;
    }
}
