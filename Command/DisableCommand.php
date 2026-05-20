<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:disable',
    description: 'Disable maintenance mode and clear activation context (reason / retry-after)',
)]
final class DisableCommand extends Command
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
    ) {
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
        $this->helper->deactivate();
        $this->context->clear();

        $output->writeln('<info>Maintenance mode disabled.</info>');

        return Command::SUCCESS;
    }
}
