<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\DebugCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class DebugCommandStatusTest extends TestCase
{
    private function fakeStorage(array $overrides = []): ContextStorageInterface
    {
        return new class ($overrides) implements ContextStorageInterface {
            private array $state;
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
            public function load(): array
            {
                return $this->state;
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
            ): void {}
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };
    }

    private function makeCommand(
        bool $isActive,
        array $rules,
        array $storageOverrides = [],
        ?PreAnnounceStorage $preAnnounceStorage = null,
    ): DebugCommand {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn($isActive);

        $evaluator = new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $this->createStub(RequestMatcherInterface::class)),
            new CommandRuleMatcher(),
            $rules,
        );

        $preAnnounceStorage ??= new class extends PreAnnounceStorage {
            public function load(): ?PreAnnounceData
            {
                return null;
            }
        };

        return new DebugCommand(
            helper: $helper,
            evaluator: $evaluator,
            context: new ActivationContext($this->fakeStorage($storageOverrides)),
            config: new BundleConfiguration(
                bypassAuthenticatedAdmins: true,
                defaultRetryAfter: 300,
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
            ),
            compiledRules: $rules,
            preAnnounceStorage: $preAnnounceStorage,
        );
    }

    // ── Existing tests ─────────────────────────────────────────────────────────

    public function testStatusOutputShowsActiveStateAndRuleGroups(): void
    {
        $rules = [
            new HttpRule('health', RuleSource::Yaml, pathGlob: '/health'),
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
            new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml),
        ];
        $tester = new CommandTester($this->makeCommand(
            isActive: true,
            rules: $rules,
            storageOverrides: ['reason' => 'DB migration v3.5'],
        ));

        $tester->execute([]);

        $out = $tester->getDisplay();
        self::assertStringContainsString('ACTIVE', $out);
        self::assertStringContainsString('DB migration v3.5', $out);
        self::assertStringContainsString('HTTP rules', $out);
        self::assertStringContainsString('/health', $out);
        self::assertStringContainsString('Command rules', $out);
        self::assertStringContainsString('messenger:*', $out);
        self::assertStringContainsString('IP rules', $out);
        self::assertStringContainsString('10.0.0.0/8', $out);
    }

    public function testStatusOutputWhenInactive(): void
    {
        $tester = new CommandTester($this->makeCommand(isActive: false, rules: []));

        $tester->execute([]);

        self::assertStringContainsString('OFF', $tester->getDisplay());
    }

    public function testStatusOutputShowsPreAnnouncement(): void
    {
        $at = new DateTimeImmutable('+2 hours', new DateTimeZone('UTC'));
        $preAnnounceStorage = new class ($at) extends PreAnnounceStorage {
            public function __construct(private readonly DateTimeImmutable $at) {}
            public function load(): ?PreAnnounceData
            {
                return new PreAnnounceData($this->at, 'UTC', 'Deployment', null);
            }
        };

        $tester = new CommandTester($this->makeCommand(isActive: false, rules: [], preAnnounceStorage: $preAnnounceStorage));
        $tester->execute([]);

        $out = $tester->getDisplay();
        self::assertStringContainsString('Pre-announcement', $out);
        self::assertStringContainsString('Deployment', $out);
    }

    // ── New TTL status tests ───────────────────────────────────────────────────

    public function testTtlLineShownAsIndefiniteWhenNoExpiresAt(): void
    {
        $tester = new CommandTester($this->makeCommand(
            isActive: true,
            rules: [],
            storageOverrides: ['expires_at' => null, 'activated_by_schedule_window_id' => null],
        ));

        $tester->execute([]);

        self::assertStringContainsString('not set (indefinite)', $tester->getDisplay());
    }

    public function testTtlLineShownWithRemainingMinutes(): void
    {
        $expiresAt = (new DateTimeImmutable('now UTC'))->modify('+47 minutes');

        $tester = new CommandTester($this->makeCommand(
            isActive: true,
            rules: [],
            storageOverrides: [
                'expires_at'                      => $expiresAt->format(DateTimeInterface::ATOM),
                'activated_by_schedule_window_id' => null,
            ],
        ));

        $tester->execute([]);

        $out = $tester->getDisplay();
        self::assertStringContainsString('min remaining', $out);
        self::assertStringContainsString('TTL:', $out);
    }

    public function testTtlLineOmittedWhenModeOff(): void
    {
        $tester = new CommandTester($this->makeCommand(isActive: false, rules: []));

        $tester->execute([]);

        self::assertStringNotContainsString('TTL:', $tester->getDisplay());
    }

    public function testTtlLineOmittedWhenScheduleManaged(): void
    {
        $expiresAt = (new DateTimeImmutable('now UTC'))->modify('+30 minutes');
        $tester = new CommandTester($this->makeCommand(
            isActive: true,
            rules: [],
            storageOverrides: [
                'expires_at'                      => $expiresAt->format(DateTimeInterface::ATOM),
                'activated_by_schedule_window_id' => 'window-nightly',
            ],
        ));

        $tester->execute([]);

        self::assertStringNotContainsString('TTL:', $tester->getDisplay());
    }
}
