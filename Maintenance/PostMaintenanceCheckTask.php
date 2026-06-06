<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Maintenance;

use Pimcore\Maintenance\TaskInterface;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckResult;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckRunnerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PendingHealthCheckStorage;

final class PostMaintenanceCheckTask implements TaskInterface
{
    public function __construct(
        private readonly PendingHealthCheckStorage $pendingStorage,
        private readonly HealthCheckRunnerInterface $runner,
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly LoggerInterface $logger,
        private readonly mixed $cacheCleanup,
        private readonly ?MaintenanceMailNotifier $mailNotifier,
        private readonly ?MaintenanceWebhookNotifier $webhookNotifier,
        private readonly string $sessionId,
    ) {}

    #[\Override]
    public function execute(): void
    {
        $pending = $this->pendingStorage->read();

        if ($pending === null) {
            return;
        }

        $results = $this->runner->runAll();

        if ($this->runner->allPassed($results)) {
            $this->pendingStorage->clear();
            $this->logger->info(
                \sprintf('Health checks passed after maintenance: %d checks ok', \count($results)),
            );
            if ($this->cacheCleanup !== null) {
                $this->cacheCleanup->run();
            }

            return;
        }

        $retryCount = $pending['retry_count'];

        if ($retryCount < 2) {
            $failed = \array_values(\array_filter($results, fn(HealthCheckResult $r) => !$r->passed));
            $firstName = $failed[0]->checkName ?? 'unknown';
            $firstError = $failed[0]->errorMessage ?? '';

            $this->pendingStorage->incrementRetryCount();
            $this->logger->warning(
                \sprintf(
                    'Health check failed (attempt %d/3): check=%s error="%s"',
                    $retryCount + 1,
                    $firstName,
                    $firstError,
                ),
            );

            return;
        }

        // retry_count === 2 → third failure, retries exhausted
        $failed = \array_values(\array_filter($results, fn(HealthCheckResult $r) => !$r->passed));
        $failedNames = \implode(', ', \array_map(fn(HealthCheckResult $r) => $r->checkName, $failed));

        $this->pendingStorage->clear();

        $reason = 'Health checks failed after maintenance — manual intervention required';
        $this->helper->activate($this->sessionId);
        $this->context->set(
            reason: $reason,
            retryAfter: null,
            activatedByScheduleWindowId: null,
            expectedEndAt: null,
            activatedByHealthCheckFailure: true,
        );

        $this->logger->error(
            \sprintf(
                'Health checks failed after 3 attempts — re-entering maintenance mode: failed=%s',
                $failedNames,
            ),
        );

        if ($this->mailNotifier !== null) {
            $this->mailNotifier->notifyMaintenanceStart($reason, null, 'health-check-failure');
        }

        if ($this->webhookNotifier !== null) {
            $this->webhookNotifier->notifyMaintenanceStart($reason, null, 'health-check-failure');
        }
    }
}
