<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\TwoChainAdvancedMaintenanceModeExtension;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\CompiledRulesProvider;

final class ExtensionWiringTest extends TestCase
{
    public function testCompiledRulesAreInjectedAsServiceArguments(): void
    {
        $container = new ContainerBuilder();
        // Default env values for our env-driven rules.
        $container->setParameter('env(default::ADVANCED_MAINTENANCE_EXEMPT_COMMANDS)', '');
        $container->setParameter('env(default::ADVANCED_MAINTENANCE_EXEMPT_ROUTES)',   '');
        $container->setParameter('env(default::ADVANCED_MAINTENANCE_EXEMPT_IPS)',      '');

        $ext = new TwoChainAdvancedMaintenanceModeExtension();
        $ext->load([[
            'exemptions' => [
                'commands' => ['messenger:*'],
                'routes' => [['path' => '/health']],
                'ips' => ['10.0.0.0/8'],
            ],
        ]], $container);

        // Rules are stored as scalar arrays on CompiledRulesProvider (XmlDumper-safe).
        $providerDef = $container->getDefinition(CompiledRulesProvider::class);
        /** @var list<CompiledRulesProvider::SerializedRule> $rulesData */
        $rulesData = (array) $providerDef->getArgument('$rulesData');

        // Hydrate to Rule DTOs for assertion.
        $rules = (new CompiledRulesProvider($rulesData))->getRules();

        $ids = \array_map(static fn ($r) => $r->id, $rules);

        // Built-ins (all default true)
        self::assertContains('bundle-own-commands', $ids);
        self::assertContains('loopback-ipv4', $ids);
        self::assertContains('loopback-ipv6', $ids);

        // YAML
        $cmd = array_values(array_filter($rules, fn ($r) => $r instanceof CommandRule && $r->namePattern === 'messenger:*'))[0] ?? null;
        self::assertNotNull($cmd);

        $route = array_values(array_filter($rules, fn ($r) => $r instanceof HttpRule && $r->pathGlob === '/health'))[0] ?? null;
        self::assertNotNull($route);

        $ip = array_values(array_filter($rules, fn ($r) => $r instanceof IpRule && $r->ipOrCidr === '10.0.0.0/8'))[0] ?? null;
        self::assertNotNull($ip);

        // ExemptionEvaluator and DebugCommand are now built by static factories — no
        // $compiledRules argument to assert on. The provider is the single source of truth.
    }
}
