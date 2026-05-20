<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service\Matcher;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;

final class IpRuleMatcherTest extends TestCase
{
    public function testExactIpv4Match(): void
    {
        $matcher = new IpRuleMatcher();
        $rules = [new IpRule('lan', '192.168.1.42', RuleSource::Yaml)];

        $hit = $matcher->match('192.168.1.42', $rules);

        self::assertNotNull($hit);
        self::assertSame('lan', $hit->id);
    }

    public function testCidrIpv4Match(): void
    {
        $matcher = new IpRuleMatcher();
        $rules = [new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml)];

        $hit = $matcher->match('10.5.4.3', $rules);

        self::assertNotNull($hit);
        self::assertSame('lan', $hit->id);
    }

    public function testIpv6LoopbackMatch(): void
    {
        $matcher = new IpRuleMatcher();
        $rules = [new IpRule('loopback', '::1', RuleSource::Builtin)];

        $hit = $matcher->match('::1', $rules);

        self::assertNotNull($hit);
    }

    public function testNoMatchReturnsNull(): void
    {
        $matcher = new IpRuleMatcher();
        $rules = [new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml)];

        self::assertNull($matcher->match('203.0.113.5', $rules));
    }

    public function testEmptyClientIpReturnsNull(): void
    {
        $matcher = new IpRuleMatcher();
        $rules = [new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml)];

        self::assertNull($matcher->match('', $rules));
        self::assertNull($matcher->match(null, $rules));
    }

    public function testFirstMatchWins(): void
    {
        $matcher = new IpRuleMatcher();
        $rules = [
            new IpRule('first', '10.0.0.0/8', RuleSource::Yaml),
            new IpRule('second', '10.5.0.0/16', RuleSource::Env),
        ];

        $hit = $matcher->match('10.5.4.3', $rules);

        self::assertSame('first', $hit->id);
    }
}
