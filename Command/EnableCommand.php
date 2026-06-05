<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:enable',
    description: 'Enable maintenance mode with optional reason and Retry-After metadata',
)]
final class EnableCommand extends Command
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly PreAnnounceStorage $preAnnounceStorage,
        private readonly MaintenanceMailNotifier $mailNotifier,
        private readonly MaintenanceWebhookNotifier $webhookNotifier,
        private readonly BundleConfiguration $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason surfaced in template, header, banner')
            ->addOption('retry-after', null, InputOption::VALUE_REQUIRED, 'Override default_retry_after for this activation (seconds)')
            ->addOption('session-id', null, InputOption::VALUE_REQUIRED, "Activator session id (defaults to 'command-line-dummy-session-id')");
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
        $sessionId = $input->getOption('session-id') ?? 'command-line-dummy-session-id';
        \assert(\is_string($sessionId));

        $reason = $input->getOption('reason');
        $reason = \is_string($reason) && $reason !== '' ? $reason : null;

        $retryRaw = $input->getOption('retry-after');
        $retryAfter = null;
        if ($retryRaw !== null && $retryRaw !== '') {
            if (!\is_numeric($retryRaw) || (int) $retryRaw < 0) {
                $output->writeln('<error>--retry-after must be a non-negative integer</error>');
                return Command::FAILURE;
            }
            $retryAfter = (int) $retryRaw;
        }

        $this->helper->activate($sessionId);
        $this->context->set($reason, $retryAfter);
        $this->preAnnounceStorage->clear();

        if ($this->config->mailOnMaintenanceStart) {
            $this->mailNotifier->notifyMaintenanceStart($reason, $retryAfter, $sessionId);
        }
        if ($this->config->notificationWebhooks !== []) {
            $this->webhookNotifier->notifyMaintenanceStart($reason, $retryAfter, $sessionId);
        }

        $output->writeln('<info>Maintenance mode enabled.</info>');
        if ($reason !== null) {
            $output->writeln(\sprintf('Reason:      %s', $reason));
        }
        if ($retryAfter !== null) {
            $output->writeln(\sprintf('Retry-After: %ds', $retryAfter));
        }

        return Command::SUCCESS;
    }
}
