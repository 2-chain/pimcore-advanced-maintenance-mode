<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckResult;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckRunnerInterface;
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
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly MaintenanceMailNotifier $mailNotifier,
        private readonly MaintenanceWebhookNotifier $webhookNotifier,
        private readonly BundleConfiguration $config,
        private readonly ?HealthCheckRunnerInterface $runner = null,
        private readonly int $retryDelaySec = 30,
        private readonly mixed $cacheCleanup = null,
        private readonly string $sessionId = 'advanced-maintenance',
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

        if ($this->runner === null) {
            return Command::SUCCESS;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $results = $this->runner->runAll();

            if ($this->runner->allPassed($results)) {
                $output->writeln('<info>Health checks passed.</info>');

                if ($this->cacheCleanup !== null) {
                    $this->cacheCleanup->run();
                }

                return Command::SUCCESS;
            }

            $failed = \array_values(\array_filter($results, fn(HealthCheckResult $r) => !$r->passed));
            $output->writeln(\sprintf(
                '<comment>Health check failed (attempt %d/%d): %s — %s</comment>',
                $attempt,
                self::MAX_ATTEMPTS,
                $failed[0]->checkName ?? 'unknown',
                $failed[0]->errorMessage ?? '',
            ));

            if ($attempt < self::MAX_ATTEMPTS && $this->retryDelaySec > 0) {
                \sleep($this->retryDelaySec);
            }
        }

        // All attempts exhausted — re-enter maintenance mode
        $reason = 'Health checks failed after maintenance — manual intervention required';
        $this->helper->activate($this->sessionId);
        $this->context->set(
            reason: $reason,
            retryAfter: null,
            activatedByScheduleWindowId: null,
            expectedEndAt: null,
            activatedByHealthCheckFailure: true,
        );

        $output->writeln('<error>Health checks failed after 3 attempts — maintenance mode re-enabled.</error>');

        $this->mailNotifier->notifyMaintenanceStart($reason, null, 'health-check-failure');
        $this->webhookNotifier->notifyMaintenanceStart($reason, null, 'health-check-failure');

        return Command::FAILURE;
    }
}
