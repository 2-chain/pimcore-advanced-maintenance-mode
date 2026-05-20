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

final class DebugCommandStatusTest extends TestCase
{
    private function makeCommand(bool $isActive, array $rules, ?string $reason = null): DebugCommand
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn($isActive);

        $storage = new class($reason) implements ContextStorageInterface {
            public function __construct(private readonly ?string $reason) {}
            public function load(): array { return ['reason' => $this->reason, 'retry_after' => null]; }
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

    public function testStatusOutputShowsActiveStateAndRuleGroups(): void
    {
        $rules = [
            new HttpRule('health', RuleSource::Yaml, pathGlob: '/health'),
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
            new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml),
        ];
        $tester = new CommandTester($this->makeCommand(isActive: true, rules: $rules, reason: 'DB migration v3.5'));

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
}
