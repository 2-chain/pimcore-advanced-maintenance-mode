<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\Compiler\AttributeExemptionDiscoveryPass;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\CompiledRulesProvider;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures\ExemptCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures\ExemptController;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures\ExemptInvokableController;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures\ExemptWithoutSibling;
use LogicException;

final class AttributeExemptionDiscoveryPassTest extends TestCase
{
    private function buildContainer(array $classes): ContainerBuilder
    {
        $c = new ContainerBuilder();

        // The pass reads/writes the $rulesData argument on CompiledRulesProvider.
        $provider = new Definition(CompiledRulesProvider::class);
        $provider->setArgument('$rulesData', []);
        $c->setDefinition(CompiledRulesProvider::class, $provider);

        foreach ($classes as $idx => $class) {
            $c->setDefinition('svc_' . $idx, new Definition($class));
        }
        return $c;
    }

    /** @return list<HttpRule|CommandRule|\TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule> */
    private function getRules(ContainerBuilder $c): array
    {
        $def = $c->getDefinition(CompiledRulesProvider::class);
        /** @var list<CompiledRulesProvider::SerializedRule> $rulesData */
        $rulesData = (array) $def->getArgument('$rulesData');
        return (new CompiledRulesProvider($rulesData))->getRules();
    }

    public function testMethodLevelAttributeDerivesRouteName(): void
    {
        $c = $this->buildContainer([ExemptController::class]);
        (new AttributeExemptionDiscoveryPass())->process($c);

        $rules = $this->getRules($c);
        $byId = [];
        foreach ($rules as $r) {
            $byId[$r->id] = $r;
        }

        self::assertArrayHasKey('orders-list', $byId);
        self::assertInstanceOf(HttpRule::class, $byId['orders-list']);
        self::assertSame('app_api_orders', $byId['orders-list']->routeName);
        self::assertSame(RuleSource::Attribute, $byId['orders-list']->source);
    }

    public function testMethodLevelAttributeWithoutRouteNameFallsBackToPath(): void
    {
        $c = $this->buildContainer([ExemptController::class]);
        (new AttributeExemptionDiscoveryPass())->process($c);

        $rules = $this->getRules($c);
        $fallback = array_values(array_filter($rules, static fn($r) => $r instanceof HttpRule && $r->pathGlob === '/api/internal/x'))[0] ?? null;

        self::assertNotNull($fallback);
        self::assertSame(RuleSource::Attribute, $fallback->source);
    }

    public function testClassLevelAttributeWithInvokableController(): void
    {
        $c = $this->buildContainer([ExemptInvokableController::class]);
        (new AttributeExemptionDiscoveryPass())->process($c);

        $rules = $this->getRules($c);
        $rule = array_values(array_filter($rules, static fn($r) => $r instanceof HttpRule && $r->id === 'order-webhook'))[0] ?? null;

        self::assertNotNull($rule);
        self::assertSame('app_webhooks_orders', $rule->routeName);
        self::assertSame(['POST'], $rule->methods);
    }

    public function testCommandClassAttribute(): void
    {
        $c = $this->buildContainer([ExemptCommand::class]);
        (new AttributeExemptionDiscoveryPass())->process($c);

        $rules = $this->getRules($c);
        $rule = array_values(array_filter($rules, static fn($r) => $r instanceof CommandRule))[0] ?? null;

        self::assertNotNull($rule);
        self::assertSame('nightly-report', $rule->id);
        self::assertSame('app:nightly-report', $rule->namePattern);
        self::assertSame(RuleSource::Attribute, $rule->source);
    }

    public function testAttributeWithoutSiblingThrows(): void
    {
        $c = $this->buildContainer([ExemptWithoutSibling::class]);

        $this->expectException(LogicException::class);
        (new AttributeExemptionDiscoveryPass())->process($c);
    }

    public function testNonExistentClassSkipped(): void
    {
        $c = $this->buildContainer([]);
        $c->setDefinition('synthetic', new Definition('This\\Class\\Does\\Not\\Exist'));

        // Must not throw.
        (new AttributeExemptionDiscoveryPass())->process($c);

        self::assertSame([], $this->getRules($c));
    }
}
