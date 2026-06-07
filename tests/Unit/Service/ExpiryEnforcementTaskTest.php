<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExpiryEnforcementTask;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;

final class ExpiryEnforcementTaskTest extends TestCase
{
    private function fakeStorage(array $overrides = []): ContextStorageInterface
    {
        return new class ($overrides) implements ContextStorageInterface {
            public array $state;
            public ?array $lastUpdateExpiry = null;
            public bool $cleared = false;

            public function __construct(array $overrides)
            {
                $this->state = array_merge([
                    'reason'                            => null,
                    'retry_after'                       => null,
                    'activated_by_schedule_window_id'   => null,
                    'expected_end_at'                   => null,
                    'activated_by_health_check_failure' => false,
                    'activated_by_history_record_id'    => null,
                    'expires_at'                        => null,
                    'original_ttl_minutes'              => null,
                    'warning_emitted_at'                => null,
                ], $overrides);
            }

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
            ): void {}

            public function updateExpiry(
                ?string $expiresAt,
                ?int $originalTtlMinutes,
                ?string $warningEmittedAt,
            ): void {
                $this->lastUpdateExpiry = [
                    'expires_at'           => $expiresAt,
                    'original_ttl_minutes' => $originalTtlMinutes,
                    'warning_emitted_at'   => $warningEmittedAt,
                ];
                $this->state['expires_at']           = $expiresAt;
                $this->state['original_ttl_minutes'] = $originalTtlMinutes;
                $this->state['warning_emitted_at']   = $warningEmittedAt;
            }

            public function saveScope(?array $scopeRaw): void {}

            public function clear(): void
            {
                $this->cleared = true;
                foreach ($this->state as $k => $_) {
                    $this->state[$k] = null;
                }
                $this->state['activated_by_health_check_failure'] = false;
            }
        };
    }

    private function makeConfig(?int $expiryWarningThreshold = null): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: true,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: $expiryWarningThreshold,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: null,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: false,
            mailOnMaintenanceEnd: false,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            mailPreAnnounceTemplate: null,
            mailMaintenanceStartTemplate: null,
            mailMaintenanceEndTemplate: null,
            notificationWebhooks: [],
        );
    }

    private function makeTask(
        bool $isActive,
        array $storageOverrides,
        ?int $expiryWarningThreshold = null,
        ?LoggerInterface $logger = null,
    ): array {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn($isActive);

        $storage = $this->fakeStorage($storageOverrides);
        $context = new ActivationContext($storage);
        $config = $this->makeConfig($expiryWarningThreshold);
        $logger ??= $this->createMock(LoggerInterface::class);

        $task = new ExpiryEnforcementTask($helper, $context, $config, $logger);

        return [$task, $helper, $storage, $logger];
    }

    public function testSkipsWhenModeIsOff(): void
    {
        [$task, $helper] = $this->makeTask(
            isActive: false,
            storageOverrides: ['expires_at' => (new \DateTimeImmutable('-10 seconds'))->format(\DateTimeInterface::ATOM)],
        );

        $helper->expects(self::never())->method('deactivate');

        $task->execute();
    }

    public function testSkipsWhenActivatedByScheduleWindow(): void
    {
        [$task, $helper] = $this->makeTask(
            isActive: true,
            storageOverrides: [
                'activated_by_schedule_window_id' => 'window-nightly',
                'expires_at' => (new \DateTimeImmutable('-10 seconds'))->format(\DateTimeInterface::ATOM),
            ],
        );

        $helper->expects(self::never())->method('deactivate');

        $task->execute();
    }

    public function testSkipsWhenNoExpiresAt(): void
    {
        [$task, $helper] = $this->makeTask(
            isActive: true,
            storageOverrides: ['expires_at' => null],
        );

        $helper->expects(self::never())->method('deactivate');

        $task->execute();
    }

    public function testDeactivatesWhenPastExpiry(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $helper->expects(self::once())->method('deactivate');

        $storage = $this->fakeStorage([
            'expires_at' => (new \DateTimeImmutable('now UTC'))->modify('-1 second')->format(\DateTimeInterface::ATOM),
        ]);
        $context = new ActivationContext($storage);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Maintenance mode auto-deactivated: TTL expired',
        );

        $task = new ExpiryEnforcementTask($helper, $context, $this->makeConfig(), $logger);
        $task->execute();

        self::assertTrue($storage->cleared);
    }

    public function testDeactivatesExactlyAtExpiry(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $helper->expects(self::once())->method('deactivate');

        $storage = $this->fakeStorage([
            'expires_at' => (new \DateTimeImmutable('now UTC'))->format(\DateTimeInterface::ATOM),
        ]);
        $context = new ActivationContext($storage);
        $logger = $this->createMock(LoggerInterface::class);

        $task = new ExpiryEnforcementTask($helper, $context, $this->makeConfig(), $logger);
        $task->execute();

        self::assertTrue($storage->cleared);
    }

    public function testEmitsWarningWhenInsideThresholdAndNotYetWarned(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $helper->expects(self::never())->method('deactivate');

        $expiresAt = (new \DateTimeImmutable('now UTC'))->modify('+5 minutes');
        $storage = $this->fakeStorage([
            'expires_at'           => $expiresAt->format(\DateTimeInterface::ATOM),
            'original_ttl_minutes' => 60,
            'warning_emitted_at'   => null,
        ]);
        $context = new ActivationContext($storage);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $task = new ExpiryEnforcementTask($helper, $context, $this->makeConfig(expiryWarningThreshold: 10), $logger);
        $task->execute();

        self::assertNotNull($storage->lastUpdateExpiry);
        self::assertNotNull($storage->lastUpdateExpiry['warning_emitted_at']);
    }

    public function testSkipsWarningWhenAlreadyWarnedWithinThreshold(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);

        $expiresAt = (new \DateTimeImmutable('now UTC'))->modify('+5 minutes');
        $warnedAt  = (new \DateTimeImmutable('now UTC'))->modify('-2 minutes');
        $storage = $this->fakeStorage([
            'expires_at'           => $expiresAt->format(\DateTimeInterface::ATOM),
            'original_ttl_minutes' => 60,
            'warning_emitted_at'   => $warnedAt->format(\DateTimeInterface::ATOM),
        ]);
        $context = new ActivationContext($storage);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $task = new ExpiryEnforcementTask($helper, $context, $this->makeConfig(expiryWarningThreshold: 10), $logger);
        $task->execute();

        self::assertNull($storage->lastUpdateExpiry);
    }

    public function testNoWarningWhenThresholdNotConfigured(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);

        $expiresAt = (new \DateTimeImmutable('now UTC'))->modify('+5 minutes');
        $storage = $this->fakeStorage([
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
        $context = new ActivationContext($storage);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('info');

        $task = new ExpiryEnforcementTask($helper, $context, $this->makeConfig(expiryWarningThreshold: null), $logger);
        $task->execute();
    }

    public function testNoWarningWhenOutsideThreshold(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);

        $expiresAt = (new \DateTimeImmutable('now UTC'))->modify('+30 minutes');
        $storage = $this->fakeStorage([
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
        $context = new ActivationContext($storage);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $task = new ExpiryEnforcementTask($helper, $context, $this->makeConfig(expiryWarningThreshold: 10), $logger);
        $task->execute();
    }
}
