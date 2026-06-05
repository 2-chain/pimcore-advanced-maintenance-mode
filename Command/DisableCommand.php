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
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:disable',
    description: 'Disable maintenance mode and clear activation context (reason / retry-after)',
)]
final class DisableCommand extends Command
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly MaintenanceMailNotifier $mailNotifier,
        private readonly MaintenanceWebhookNotifier $webhookNotifier,
        private readonly BundleConfiguration $config,
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

        if ($this->config->mailOnMaintenanceEnd) {
            $this->mailNotifier->notifyMaintenanceEnd('disable');
        }
        if ($this->config->notificationWebhooks !== []) {
            $this->webhookNotifier->notifyMaintenanceEnd('disable');
        }

        $output->writeln('<info>Maintenance mode disabled.</info>');

        return Command::SUCCESS;
    }
}
