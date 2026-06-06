<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\EnableCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

final class EnableCommandTest extends TestCase
{
    /** @return array{ActivationContext, object} */
    private function fakeContext(): array
    {
        $storage = new class implements ContextStorageInterface {
            public ?string $reason = null;
            public ?int $retry = null;
            public ?string $expiresAt = null;
            public ?int $originalTtlMinutes = null;
            public bool $cleared = false;

            public function load(): array
            {
                return [
                    'reason'                            => $this->reason,
                    'retry_after'                       => $this->retry,
                    'activated_by_schedule_window_id'   => null,
                    'expected_end_at'                   => null,
                    'activated_by_health_check_failure' => false,
                    'activated_by_history_record_id'    => null,
                    'expires_at'                        => $this->expiresAt,
                    'original_ttl_minutes'              => $this->originalTtlMinutes,
                    'warning_emitted_at'                => null,
                ];
            }

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
                $this->reason             = $reason;
                $this->retry              = $retryAfter;
                $this->expiresAt          = $expiresAt;
                $this->originalTtlMinutes = $originalTtlMinutes;
            }

            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}

            public function saveScope(?array $scopeRaw): void {}

            public function clear(): void
            {
                $this->cleared            = true;
                $this->reason             = null;
                $this->retry              = null;
                $this->expiresAt          = null;
                $this->originalTtlMinutes = null;
            }
        };
        return [new ActivationContext($storage), $storage];
    }

    private function makeConfig(
        bool $mailOnStart = false,
        array $webhooks = [],
        ?int $defaultTtl = null,
    ): BundleConfiguration {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            defaultTtl: $defaultTtl,
            expiryWarningThreshold: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: 60,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: $mailOnStart,
            mailOnMaintenanceEnd: false,
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

    private function makeEnableCommand(
        MaintenanceModeHelperInterface $helper,
        ActivationContext $context,
        ?PreAnnounceStorage $preAnnounceStorage = null,
        ?MaintenanceMailNotifier $mailNotifier = null,
        ?MaintenanceWebhookNotifier $webhookNotifier = null,
        ?BundleConfiguration $config = null,
        ?LoggerInterface $logger = null,
    ): EnableCommand {
        return new EnableCommand(
            $helper,
            $context,
            $preAnnounceStorage ?? $this->createStub(PreAnnounceStorage::class),
            $mailNotifier ?? $this->createStub(MaintenanceMailNotifier::class),
            $webhookNotifier ?? $this->createStub(MaintenanceWebhookNotifier::class),
            $config ?? $this->makeConfig(),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    // ── Existing tests ─────────────────────────────────────────────────────────

    public function testActivatesWithDefaults(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('activate')->with('command-line-dummy-session-id');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($storage->reason);
        self::assertNull($storage->retry);
        self::assertNull($storage->expiresAt);
        self::assertStringContainsString('Maintenance mode enabled', $tester->getDisplay());
    }

    public function testActivatesWithReasonAndRetryAfter(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('activate')->with('custom-session-id');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute([
            '--reason'      => 'DB migration v3.5',
            '--retry-after' => '600',
            '--session-id'  => 'custom-session-id',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('DB migration v3.5', $storage->reason);
        self::assertSame(600, $storage->retry);
    }

    public function testClearsPreAnnounceStorageOnEnable(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        [$context,] = $this->fakeContext();

        $preAnnounceStorage = new class extends PreAnnounceStorage {
            public bool $cleared = false;
            public function clear(): void { $this->cleared = true; }
        };

        $tester = new CommandTester($this->makeEnableCommand($helper, $context, $preAnnounceStorage));
        $tester->execute([]);

        self::assertTrue($preAnnounceStorage->cleared);
    }

    public function testFiresMailNotifierWhenConfigured(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        [$context,] = $this->fakeContext();

        $mailNotifier = $this->createMock(MaintenanceMailNotifier::class);
        $mailNotifier->expects(self::once())->method('notifyMaintenanceStart');

        $tester = new CommandTester($this->makeEnableCommand(
            $helper, $context,
            config: $this->makeConfig(mailOnStart: true),
            mailNotifier: $mailNotifier,
        ));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    // ── New TTL tests ──────────────────────────────────────────────────────────

    public function testExpiresInFlagSetsExpiresAt(): void
    {
        $before = new \DateTimeImmutable('now UTC');

        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('activate');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute(['--expires-in' => '60']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(60, $storage->originalTtlMinutes);
        self::assertNotNull($storage->expiresAt);

        $expiresAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $storage->expiresAt);
        self::assertNotFalse($expiresAt);
        $expectedMin = $before->modify('+60 minutes')->getTimestamp();
        self::assertGreaterThanOrEqual($expectedMin - 5, $expiresAt->getTimestamp());
        self::assertLessThanOrEqual($expectedMin + 5, $expiresAt->getTimestamp());
    }

    public function testExpiresInPrintedInOutput(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('activate');

        [$context] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));
        $tester->execute(['--expires-in' => '60']);

        $out = $tester->getDisplay();
        self::assertStringContainsString('Expires at:', $out);
        self::assertStringContainsString('60 min', $out);
    }

    public function testDefaultTtlFromConfigUsedWhenNoFlag(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('activate');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand(
            $helper, $context,
            config: $this->makeConfig(defaultTtl: 30),
        ));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(30, $storage->originalTtlMinutes);
        self::assertNotNull($storage->expiresAt);
    }

    public function testNoTtlWhenNeitherFlagNorConfigSet(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('activate');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context, config: $this->makeConfig(defaultTtl: null)));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($storage->expiresAt);
        self::assertNull($storage->originalTtlMinutes);
    }

    public function testExpiresInMustBePositiveInteger(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        [$context] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute(['--expires-in' => '0']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--expires-in must be a positive integer', $tester->getDisplay());
    }

    public function testExpiresInMustBeNumeric(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        [$context] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute(['--expires-in' => 'abc']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--expires-in must be a positive integer', $tester->getDisplay());
    }

    public function testFlagTakesPrecedenceOverConfigTtl(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('activate');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand(
            $helper, $context,
            config: $this->makeConfig(defaultTtl: 30),
        ));

        $tester->execute(['--expires-in' => '90']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(90, $storage->originalTtlMinutes);
    }

    // ── Scope tests ────────────────────────────────────────────────────────────

    /** @return array{ActivationContext, object} */
    private function fakeContextWithScope(): array
    {
        $storage = new class implements ContextStorageInterface {
            public ?string $reason         = null;
            public ?int    $retry          = null;
            public ?array  $scopeRaw       = null;
            public ?string $expiresAt      = null;
            public ?int    $originalTtl    = null;

            public function load(): array
            {
                return [
                    'reason'                            => $this->reason,
                    'retry_after'                       => $this->retry,
                    'activated_by_schedule_window_id'   => null,
                    'expected_end_at'                   => null,
                    'activated_by_health_check_failure' => false,
                    'activated_by_history_record_id'    => null,
                    'expires_at'                        => $this->expiresAt,
                    'original_ttl_minutes'              => $this->originalTtl,
                    'warning_emitted_at'                => null,
                    'scope'                             => $this->scopeRaw,
                ];
            }

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
                $this->reason       = $reason;
                $this->retry        = $retryAfter;
                $this->expiresAt    = $expiresAt;
                $this->originalTtl  = $originalTtlMinutes;
            }

            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {
                $this->expiresAt   = $expiresAt;
                $this->originalTtl = $originalTtlMinutes;
            }

            public function saveScope(?array $scopeRaw): void
            {
                $this->scopeRaw = $scopeRaw;
            }

            public function clear(): void
            {
                $this->reason    = null;
                $this->retry     = null;
                $this->expiresAt = null;
                $this->originalTtl = null;
                $this->scopeRaw  = null;
            }
        };

        return [new ActivationContext($storage), $storage];
    }

    public function testScopeOptionsStoredWhenProvided(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('activate');

        [$context, $storage] = $this->fakeContextWithScope();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute([
            '--path-prefix' => ['/shop', '/api'],
            '--site-id'     => ['2'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertNotNull($storage->scopeRaw);
        self::assertSame(['/shop', '/api'], $storage->scopeRaw['path_prefixes']);
        self::assertSame([2], $storage->scopeRaw['site_ids']);
        self::assertStringContainsString('/shop', $tester->getDisplay());
    }

    public function testNoScopeNoYamlDefaultStoresNullScope(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('activate');

        [$context, $storage] = $this->fakeContextWithScope();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($storage->scopeRaw);
        self::assertStringContainsString('Scope:', $tester->getDisplay());
        self::assertStringContainsString('global', $tester->getDisplay());
    }

    public function testDefaultScopeFromConfigUsedWhenNoFlag(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('activate');

        [$context, $storage] = $this->fakeContextWithScope();
        $configWithScope = new BundleConfiguration(
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
            defaultScopeData: ['path_prefixes' => ['/checkout'], 'site_ids' => []],
        );

        $tester = new CommandTester($this->makeEnableCommand($helper, $context, config: $configWithScope));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertNotNull($storage->scopeRaw);
        self::assertSame(['/checkout'], $storage->scopeRaw['path_prefixes']);
    }
}
