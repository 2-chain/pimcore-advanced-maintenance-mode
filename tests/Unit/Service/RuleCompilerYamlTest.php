<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\RuleCompiler;

final class RuleCompilerYamlTest extends TestCase
{
    private function processed(array $exemptions = []): array
    {
        return [
            'exemptions' => \array_merge([
                'commands' => [],
                'routes'   => [],
                'ips'      => [],
                'messenger_workers' => false,
                'scheduled_tasks'   => false,
            ], $exemptions),
            'builtin_exemptions' => [
                'bundle_own_commands'   => false,
                'symfony_info_commands' => false,
                'loopback'              => false,
            ],
        ];
    }

    public function testEmptyConfigYieldsNoRules(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->processed());

        self::assertSame([], $rules);
    }

    public function testCommandStringRule(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->processed([
            'commands' => [['pattern' => 'messenger:*', 'id' => null]],
        ]));

        self::assertCount(1, $rules);
        self::assertInstanceOf(CommandRule::class, $rules[0]);
        self::assertSame('messenger:*', $rules[0]->namePattern);
        self::assertSame(RuleSource::Yaml, $rules[0]->source);
        self::assertStringStartsWith('yaml-commands-', $rules[0]->id);
    }

    public function testCommandRuleWithExplicitId(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->processed([
            'commands' => [['pattern' => 'doctrine:migrations:*', 'id' => 'doctrine-migrations']],
        ]));

        self::assertSame('doctrine-migrations', $rules[0]->id);
    }

    public function testHttpRouteRule(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->processed([
            'routes' => [[
                'path' => '/api/*',
                'route' => null,
                'host' => 'api.example.com',
                'methods' => ['POST'],
                'id' => 'api',
            ]],
        ]));

        self::assertCount(1, $rules);
        self::assertInstanceOf(HttpRule::class, $rules[0]);
        self::assertSame('api', $rules[0]->id);
        self::assertSame('/api/*', $rules[0]->pathGlob);
        self::assertSame('api.example.com', $rules[0]->host);
        self::assertSame(['POST'], $rules[0]->methods);
    }

    public function testIpRules(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->processed([
            'ips' => ['10.0.0.0/8', '192.168.1.42'],
        ]));

        self::assertCount(2, $rules);
        self::assertInstanceOf(IpRule::class, $rules[0]);
        self::assertSame('10.0.0.0/8', $rules[0]->ipOrCidr);
    }

    public function testMessengerWorkersCoarseSwitchEmitsBuiltinRule(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->processed([
            'messenger_workers' => true,
        ]));

        self::assertCount(1, $rules);
        self::assertInstanceOf(CommandRule::class, $rules[0]);
        self::assertSame('messenger-workers', $rules[0]->id);
        self::assertSame('messenger:*', $rules[0]->namePattern);
        self::assertSame(RuleSource::Builtin, $rules[0]->source);
    }

    public function testScheduledTasksCoarseSwitch(): void
    {
        $rules = (new RuleCompiler())->compileFromConfig($this->processed([
            'scheduled_tasks' => true,
        ]));

        self::assertSame('scheduled-tasks', $rules[0]->id);
        self::assertSame('pimcore:scheduler:*', $rules[0]->namePattern);
    }
}
