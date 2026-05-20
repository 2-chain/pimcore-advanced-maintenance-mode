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
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;

final class DebugCommandSimulateTest extends TestCase
{
    private function makeCommand(array $rules): DebugCommand
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $storage = new class implements ContextStorageInterface {
            public function load(): array
            {
                return ['reason' => null, 'retry_after' => null];
            }
            public function save(?string $reason, ?int $retryAfter): void {}
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
            config: new BundleConfiguration(true, 300),
            compiledRules: $rules,
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
