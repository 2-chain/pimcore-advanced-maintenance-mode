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

final class DebugCommandSimulateTest extends TestCase
{
    private function makeCommand(array $rules): DebugCommand
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $storage = new class implements ContextStorageInterface {
            public function load(): array
            {
                return ['reason' => null, 'retry_after' => null, 'activated_by_schedule_window_id' => null, 'expected_end_at' => null, 'activated_by_health_check_failure' => false, 'activated_by_history_record_id' => null, 'expires_at' => null, 'original_ttl_minutes' => null, 'warning_emitted_at' => null];
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
        $evaluator = new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $this->createStub(RequestMatcherInterface::class)),
            new CommandRuleMatcher(),
            $rules,
        );
        return new DebugCommand(
            helper: $helper,
            evaluator: $evaluator,
            context: new ActivationContext($storage),
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
            preAnnounceStorage: new class extends PreAnnounceStorage {
                public function load(): ?PreAnnounceData { return null; }
            },
        );
    }

    public function testSimulateRouteHitsHttpRule(): void
    {
        $tester = new CommandTester($this->makeCommand([
            new HttpRule('webhook', RuleSource::Yaml, pathGlob: '/api/webhooks/*', methods: ['POST']),
        ]));

        $tester->execute(['--route' => '/api/webhooks/orders', '--method' => 'POST']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('exempted by rule', $tester->getDisplay());
        self::assertStringContainsString('webhook', $tester->getDisplay());
    }

    public function testSimulateRouteHitsIpRule(): void
    {
        $tester = new CommandTester($this->makeCommand([
            new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml),
        ]));

        $tester->execute(['--route' => '/anything', '--ip' => '10.5.4.3']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('exempted by rule', $tester->getDisplay());
        self::assertStringContainsString('lan', $tester->getDisplay());
    }

    public function testSimulateRouteNoMatch(): void
    {
        $tester = new CommandTester($this->makeCommand([
            new HttpRule('health', RuleSource::Yaml, pathGlob: '/health'),
        ]));

        $tester->execute(['--route' => '/admin']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No exemption rule matches', $tester->getDisplay());
    }

    public function testSimulateCommandHits(): void
    {
        $tester = new CommandTester($this->makeCommand([
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
        ]));

        $tester->execute(['--command' => 'messenger:consume']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('exempted by rule', $tester->getDisplay());
        self::assertStringContainsString('msg', $tester->getDisplay());
    }

    public function testSimulateCommandNoMatch(): void
    {
        $tester = new CommandTester($this->makeCommand([
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
        ]));

        $tester->execute(['--command' => 'app:other']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No exemption rule matches', $tester->getDisplay());
    }
}
