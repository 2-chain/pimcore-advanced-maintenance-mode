<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\RuleCompiler;

final class RuleCompilerEnvTest extends TestCase
{
    public function testEnvCommandsParsed(): void
    {
        $rules = (new RuleCompiler())->compileFromEnv(
            commands: 'messenger:*,doctrine:migrations:migrate',
            routes: '',
            ips: '',
        );

        self::assertCount(2, $rules);
        self::assertInstanceOf(CommandRule::class, $rules[0]);
        self::assertSame('messenger:*', $rules[0]->namePattern);
        self::assertSame(RuleSource::Env, $rules[0]->source);
        self::assertSame('env-commands-0', $rules[0]->id);
    }

    public function testEnvRoutesParsedAsPathGlobs(): void
    {
        $rules = (new RuleCompiler())->compileFromEnv(
            commands: '',
            routes: '/health,/api/webhooks/*',
            ips: '',
        );

        self::assertCount(2, $rules);
        self::assertInstanceOf(HttpRule::class, $rules[0]);
        self::assertSame('/health', $rules[0]->pathGlob);
        self::assertSame(RuleSource::Env, $rules[0]->source);
    }

    public function testEnvIpsParsed(): void
    {
        $rules = (new RuleCompiler())->compileFromEnv(
            commands: '',
            routes: '',
            ips: '10.0.0.0/8,203.0.113.42',
        );

        self::assertCount(2, $rules);
        self::assertInstanceOf(IpRule::class, $rules[0]);
        self::assertSame('10.0.0.0/8', $rules[0]->ipOrCidr);
    }

    public function testEmptyAndWhitespaceEntriesSkipped(): void
    {
        $rules = (new RuleCompiler())->compileFromEnv(
            commands: ' , messenger:*, ,doctrine:* ,',
            routes: '',
            ips: '',
        );

        self::assertCount(2, $rules);
        self::assertSame('messenger:*', $rules[0]->namePattern);
        self::assertSame('doctrine:*', $rules[1]->namePattern);
    }

    public function testAllEmptyProducesNoRules(): void
    {
        $rules = (new RuleCompiler())->compileFromEnv('', '', '');

        self::assertSame([], $rules);
    }
}
