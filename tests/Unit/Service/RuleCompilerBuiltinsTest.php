<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\RuleCompiler;

final class RuleCompilerBuiltinsTest extends TestCase
{
    private function config(array $builtin): array
    {
        return [
            'exemptions' => [
                'commands' => [], 'routes' => [], 'ips' => [],
                'messenger_workers' => false, 'scheduled_tasks' => false,
            ],
            'builtin_exemptions' => array_merge(['pimcore_maintenance' => false], $builtin),
        ];
    }

    public function testBundleOwnCommandsEnabled(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->config([
            'bundle_own_commands' => true, 'symfony_info_commands' => false, 'loopback' => false,
        ]));

        $cmd = array_values(array_filter($rules, fn($r) => $r instanceof CommandRule))[0] ?? null;
        self::assertNotNull($cmd);
        self::assertSame('bundle-own-commands', $cmd->id);
        self::assertSame('pimcore:advanced-maintenance:*', $cmd->namePattern);
        self::assertSame(RuleSource::Builtin, $cmd->source);
    }

    public function testSymfonyInfoCommandsEnabled(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->config([
            'bundle_own_commands' => false, 'symfony_info_commands' => true, 'loopback' => false,
        ]));

        $patterns = \array_map(static fn($r) => $r->namePattern, $rules);
        self::assertContains('help', $patterns);
        self::assertContains('list', $patterns);
        self::assertContains('_complete', $patterns);
        self::assertContains('completion', $patterns);
        self::assertContains('about', $patterns);
    }

    public function testLoopbackEnabledEmitsIpv4AndIpv6(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->config([
            'bundle_own_commands' => false, 'symfony_info_commands' => false, 'loopback' => true,
        ]));

        $addrs = \array_map(static fn($r) => $r->ipOrCidr, \array_filter($rules, fn($r) => $r instanceof IpRule));
        self::assertEqualsCanonicalizing(['127.0.0.1', '::1'], \array_values($addrs));
    }

    public function testAllDisabled(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->config([
            'bundle_own_commands' => false, 'symfony_info_commands' => false, 'loopback' => false,
        ]));

        self::assertSame([], $rules);
    }
}
