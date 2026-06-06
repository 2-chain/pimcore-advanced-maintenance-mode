<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\DisableCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckResult;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckRunnerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;

final class DisableCommandTest extends TestCase
{
    private function fakeStorage(): ContextStorageInterface
    {
        return new class implements ContextStorageInterface {
            public bool $cleared = false;
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
                $this->cleared = true;
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

    private function makeConfig(bool $mailOnEnd = false, array $webhooks = []): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: 60,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: false,
            mailOnMaintenanceEnd: $mailOnEnd,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            mailPreAnnounceTemplate: null,
            mailMaintenanceStartTemplate: null,
            mailMaintenanceEndTemplate: null,
            notificationWebhooks: $webhooks,
        );
    }

    public function testDeactivatesAndClearsContext(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('deactivate');

        $storage = $this->fakeStorage();
        $tester = new CommandTester(new DisableCommand(
            helper: $helper,
            context: new ActivationContext($storage),
            mailNotifier: $this->createStub(MaintenanceMailNotifier::class),
            webhookNotifier: $this->createStub(MaintenanceWebhookNotifier::class),
            config: $this->makeConfig(),
        ));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($storage->cleared);
        self::assertStringContainsString('Maintenance mode disabled', $tester->getDisplay());
    }

    public function testFiresMailNotifierWhenConfigured(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $storage = $this->fakeStorage();

        $mailNotifier = $this->createMock(MaintenanceMailNotifier::class);
        $mailNotifier->expects(self::once())->method('notifyMaintenanceEnd');

        $tester = new CommandTester(new DisableCommand(
            helper: $helper,
            context: new ActivationContext($storage),
            mailNotifier: $mailNotifier,
            webhookNotifier: $this->createStub(MaintenanceWebhookNotifier::class),
            config: $this->makeConfig(mailOnEnd: true),
        ));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    public function testSucceedsWithoutHealthCheckWhenRunnerIsNull(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('deactivate');

        $storage = $this->fakeStorage();
        $tester = new CommandTester(new DisableCommand(
            helper: $helper,
            context: new ActivationContext($storage),
            mailNotifier: $this->createStub(MaintenanceMailNotifier::class),
            webhookNotifier: $this->createStub(MaintenanceWebhookNotifier::class),
            config: $this->makeConfig(),
            runner: null,
        ));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
    }

    public function testRunsHealthChecksWhenRunnerProvided(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $storage = $this->fakeStorage();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn([
            new HealthCheckResult(passed: true, checkName: 'db-check'),
        ]);
        $runner->method('allPassed')->willReturn(true);

        $tester = new CommandTester(new DisableCommand(
            helper: $helper,
            context: new ActivationContext($storage),
            mailNotifier: $this->createStub(MaintenanceMailNotifier::class),
            webhookNotifier: $this->createStub(MaintenanceWebhookNotifier::class),
            config: $this->makeConfig(),
            runner: $runner,
            retryDelaySec: 0,
        ));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Health checks passed', $tester->getDisplay());
    }

    public function testRetriesOnFailureThenSucceeds(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $storage = $this->fakeStorage();

        $callCount = 0;
        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            $passed = $callCount >= 2; // fails once, passes on second attempt
            return [new HealthCheckResult(passed: $passed, checkName: 'db-check', errorMessage: $passed ? null : 'err')];
        });
        $runner->method('allPassed')->willReturnCallback(fn(array $r) => $r[0]->passed);

        $tester = new CommandTester(new DisableCommand(
            helper: $helper,
            context: new ActivationContext($storage),
            mailNotifier: $this->createStub(MaintenanceMailNotifier::class),
            webhookNotifier: $this->createStub(MaintenanceWebhookNotifier::class),
            config: $this->makeConfig(),
            runner: $runner,
            retryDelaySec: 0,
        ));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(2, $callCount);
    }

    public function testReEntersMaintModeAfterThreeFailures(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('deactivate');
        $helper->expects(self::once())->method('activate');

        $storage = $this->fakeStorage();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn([
            new HealthCheckResult(passed: false, checkName: 'db-check', errorMessage: 'fail'),
        ]);
        $runner->method('allPassed')->willReturn(false);

        $mailNotifier = $this->createMock(MaintenanceMailNotifier::class);
        $mailNotifier->expects(self::once())->method('notifyMaintenanceStart');

        $tester = new CommandTester(new DisableCommand(
            helper: $helper,
            context: new ActivationContext($storage),
            mailNotifier: $mailNotifier,
            webhookNotifier: $this->createStub(MaintenanceWebhookNotifier::class),
            config: $this->makeConfig(),
            runner: $runner,
            retryDelaySec: 0,
            sessionId: 'test-session',
        ));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
