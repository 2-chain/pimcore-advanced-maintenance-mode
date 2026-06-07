<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Maintenance;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckResult;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckRunnerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Maintenance\PostMaintenanceCheckTask;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PendingHealthCheckStorage;

final class PostMaintenanceCheckTaskTest extends TestCase
{
    // ------------------------------------------------------------------ helpers

    private function makeRunner(bool $allPass, string ...$failedNames): HealthCheckRunnerInterface
    {
        $results = [];
        if ($allPass) {
            $results[] = new HealthCheckResult(passed: true, checkName: 'ok-check');
        } else {
            foreach ($failedNames as $name) {
                $results[] = new HealthCheckResult(passed: false, checkName: $name, errorMessage: 'err');
            }
        }

        $runner = $this->createMock(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($results);
        $runner->method('allPassed')->willReturn($allPass);

        return $runner;
    }

    private function fakeStorage(): ContextStorageInterface
    {
        return new class implements ContextStorageInterface {
            public array $state = [
                'reason'                            => null,
                'retry_after'                       => null,
                'activated_by_schedule_window_id'   => null,
                'expected_end_at'                   => null,
                'activated_by_health_check_failure' => false,
                'activated_by_history_record_id'    => null,
                'expires_at'                        => null,
                'original_ttl_minutes'              => null,
                'warning_emitted_at'                => null,
            ];

            public function load(): array { return $this->state; }

            public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
                ?string $expiresAt = null,
                ?int $originalTtlMinutes = null,
                ?string $warningEmittedAt = null,
            ): void {
                $this->state['reason']                            = $reason;
                $this->state['retry_after']                       = $retryAfter;
                $this->state['activated_by_schedule_window_id']   = $activatedByScheduleWindowId;
                $this->state['expected_end_at']                   = $expectedEndAt;
                $this->state['activated_by_health_check_failure'] = $activatedByHealthCheckFailure;
                $this->state['activated_by_history_record_id']    = $activatedByHistoryRecordId;
            }

            public function updateExpiry(
                ?string $expiresAt,
                ?int $originalTtlMinutes,
                ?string $warningEmittedAt,
            ): void {
                $this->state['expires_at']           = $expiresAt;
                $this->state['original_ttl_minutes'] = $originalTtlMinutes;
                $this->state['warning_emitted_at']   = $warningEmittedAt;
            }

            public function saveScope(?array $scopeRaw): void {}

            public function clear(): void
            {
                $this->state = [
                    'reason'                            => null,
                    'retry_after'                       => null,
                    'activated_by_schedule_window_id'   => null,
                    'expected_end_at'                   => null,
                    'activated_by_health_check_failure' => false,
                    'activated_by_history_record_id'    => null,
                    'expires_at'                        => null,
                    'original_ttl_minutes'              => null,
                    'warning_emitted_at'                => null,
                ];
            }
        };
    }

    // ------------------------------------------------------------------ branch 1: no-op

    public function testNoOpWhenNoPendingEntry(): void
    {
        $pendingStorage = $this->createMock(PendingHealthCheckStorage::class);
        $pendingStorage->method('read')->willReturn(null);
        $pendingStorage->expects(self::never())->method('clear');

        $runner = $this->createMock(HealthCheckRunnerInterface::class);
        $runner->expects(self::never())->method('runAll');

        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $context = new ActivationContext($this->fakeStorage());
        $logger = $this->createMock(LoggerInterface::class);

        $task = new PostMaintenanceCheckTask(
            pendingStorage: $pendingStorage,
            runner: $runner,
            helper: $helper,
            context: $context,
            logger: $logger,
            cacheCleanup: null,
            mailNotifier: null,
            webhookNotifier: null,
            sessionId: 'test-session',
        );

        $task->execute();
    }

    // ------------------------------------------------------------------ branch 2: all pass

    public function testClearsStorageAndLogsInfoWhenAllPass(): void
    {
        $pendingStorage = $this->createMock(PendingHealthCheckStorage::class);
        $pendingStorage->method('read')->willReturn([
            'retry_count' => 0, 'triggered_by' => 'schedule_ended', 'deactivated_at' => '2026-06-02T12:00:00+00:00',
        ]);
        $pendingStorage->expects(self::once())->method('clear');
        $pendingStorage->expects(self::never())->method('incrementRetryCount');

        $runner = $this->makeRunner(true);
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::never())->method('activate');
        $context = new ActivationContext($this->fakeStorage());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('Health checks passed after maintenance'),
        );

        $task = new PostMaintenanceCheckTask(
            pendingStorage: $pendingStorage,
            runner: $runner,
            helper: $helper,
            context: $context,
            logger: $logger,
            cacheCleanup: null,
            mailNotifier: null,
            webhookNotifier: null,
            sessionId: 'test-session',
        );

        $task->execute();
    }

    // ------------------------------------------------------------------ branch 3: fail, retry pending

    public function testIncrementsRetryCountAndLogsWarningOnFirstFailure(): void
    {
        $pendingStorage = $this->createMock(PendingHealthCheckStorage::class);
        $pendingStorage->method('read')->willReturn([
            'retry_count' => 0, 'triggered_by' => 'schedule_ended', 'deactivated_at' => '2026-06-02T12:00:00+00:00',
        ]);
        $pendingStorage->expects(self::once())->method('incrementRetryCount');
        $pendingStorage->expects(self::never())->method('clear');

        $runner = $this->makeRunner(false, 'db-check');
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::never())->method('activate');
        $context = new ActivationContext($this->fakeStorage());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('Health check failed'),
        );

        $task = new PostMaintenanceCheckTask(
            pendingStorage: $pendingStorage,
            runner: $runner,
            helper: $helper,
            context: $context,
            logger: $logger,
            cacheCleanup: null,
            mailNotifier: null,
            webhookNotifier: null,
            sessionId: 'test-session',
        );

        $task->execute();
    }

    public function testIncrementsRetryCountOnSecondFailure(): void
    {
        $pendingStorage = $this->createMock(PendingHealthCheckStorage::class);
        $pendingStorage->method('read')->willReturn([
            'retry_count' => 1, 'triggered_by' => 'schedule_ended', 'deactivated_at' => '2026-06-02T12:00:00+00:00',
        ]);
        $pendingStorage->expects(self::once())->method('incrementRetryCount');
        $pendingStorage->expects(self::never())->method('clear');

        $runner = $this->makeRunner(false, 'http-check');
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::never())->method('activate');
        $context = new ActivationContext($this->fakeStorage());
        $logger = $this->createMock(LoggerInterface::class);

        $task = new PostMaintenanceCheckTask(
            pendingStorage: $pendingStorage,
            runner: $runner,
            helper: $helper,
            context: $context,
            logger: $logger,
            cacheCleanup: null,
            mailNotifier: null,
            webhookNotifier: null,
            sessionId: 'test-session',
        );

        $task->execute();
    }

    // ------------------------------------------------------------------ branch 4: fail, retries exhausted

    public function testReEntersMaintModeAndNotifiesAfterThirdFailure(): void
    {
        $pendingStorage = $this->createMock(PendingHealthCheckStorage::class);
        $pendingStorage->method('read')->willReturn([
            'retry_count' => 2, 'triggered_by' => 'schedule_ended', 'deactivated_at' => '2026-06-02T12:00:00+00:00',
        ]);
        $pendingStorage->expects(self::once())->method('clear');
        $pendingStorage->expects(self::never())->method('incrementRetryCount');

        $runner = $this->makeRunner(false, 'db-check', 'http-check');
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('activate')->with('test-session');

        $contextStorage = $this->fakeStorage();
        $context = new ActivationContext($contextStorage);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('error')->with(
            self::stringContains('Health checks failed after 3 attempts'),
        );

        $mailNotifier = $this->createMock(MaintenanceMailNotifier::class);
        $mailNotifier->expects(self::once())->method('notifyMaintenanceStart');

        $webhookNotifier = $this->createMock(MaintenanceWebhookNotifier::class);
        $webhookNotifier->expects(self::once())->method('notifyMaintenanceStart');

        $task = new PostMaintenanceCheckTask(
            pendingStorage: $pendingStorage,
            runner: $runner,
            helper: $helper,
            context: $context,
            logger: $logger,
            cacheCleanup: null,
            mailNotifier: $mailNotifier,
            webhookNotifier: $webhookNotifier,
            sessionId: 'test-session',
        );

        $task->execute();
    }

    public function testReEntrySetsFlagOnActivationContext(): void
    {
        $pendingStorage = $this->createMock(PendingHealthCheckStorage::class);
        $pendingStorage->method('read')->willReturn([
            'retry_count' => 2, 'triggered_by' => 'ttl_expired', 'deactivated_at' => '2026-06-02T12:00:00+00:00',
        ]);

        $runner = $this->makeRunner(false, 'http-check');
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);

        $contextStorage = $this->fakeStorage();
        $context = new ActivationContext($contextStorage);
        $logger = $this->createMock(LoggerInterface::class);

        $task = new PostMaintenanceCheckTask(
            pendingStorage: $pendingStorage,
            runner: $runner,
            helper: $helper,
            context: $context,
            logger: $logger,
            cacheCleanup: null,
            mailNotifier: null,
            webhookNotifier: null,
            sessionId: 'test-session',
        );

        $task->execute();

        self::assertTrue($context->isActivatedByHealthCheckFailure());
        self::assertNull($context->getActivatedByScheduleWindowId());
    }
}
