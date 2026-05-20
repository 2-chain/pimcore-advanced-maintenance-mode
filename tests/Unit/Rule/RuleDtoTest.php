<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Rule;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\ExemptionMatch;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;

final class RuleDtoTest extends TestCase
{
    public function testHttpRuleHoldsAllFields(): void
    {
        $rule = new HttpRule(
            id: 'health',
            source: RuleSource::Yaml,
            pathGlob: '/health',
            routeName: 'app_health',
            host: 'api.example.com',
            methods: ['GET', 'HEAD'],
        );

        self::assertSame('health', $rule->id);
        self::assertSame(RuleSource::Yaml, $rule->source);
        self::assertSame('/health', $rule->pathGlob);
        self::assertSame('app_health', $rule->routeName);
        self::assertSame('api.example.com', $rule->host);
        self::assertSame(['GET', 'HEAD'], $rule->methods);
    }

    public function testHttpRuleRejectsAllFieldsNull(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpRule(id: 'x', source: RuleSource::Yaml);
    }

    public function testCommandRuleHoldsFields(): void
    {
        $rule = new CommandRule(id: 'msg', namePattern: 'messenger:*', source: RuleSource::Builtin);

        self::assertSame('msg', $rule->id);
        self::assertSame('messenger:*', $rule->namePattern);
        self::assertSame(RuleSource::Builtin, $rule->source);
    }

    public function testCommandRuleRejectsEmptyPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CommandRule(id: 'x', namePattern: '', source: RuleSource::Yaml);
    }

    public function testIpRuleHoldsFields(): void
    {
        $rule = new IpRule(id: 'lan', ipOrCidr: '10.0.0.0/8', source: RuleSource::Env);

        self::assertSame('lan', $rule->id);
        self::assertSame('10.0.0.0/8', $rule->ipOrCidr);
    }

    public function testIpRuleRejectsEmptyAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IpRule(id: 'x', ipOrCidr: '', source: RuleSource::Yaml);
    }

    public function testExemptionMatchHoldsFields(): void
    {
        $match = new ExemptionMatch('health', RuleSource::Yaml, 'matched /health');

        self::assertSame('health', $match->ruleId);
        self::assertSame(RuleSource::Yaml, $match->source);
        self::assertSame('matched /health', $match->description);
    }

    public function testRuleSourceEnumValues(): void
    {
        self::assertSame('yaml', RuleSource::Yaml->value);
        self::assertSame('env', RuleSource::Env->value);
        self::assertSame('attribute', RuleSource::Attribute->value);
        self::assertSame('builtin', RuleSource::Builtin->value);
    }
}
