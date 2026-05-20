<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service\Matcher;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;

final class CommandRuleMatcherTest extends TestCase
{
    public function testExactMatch(): void
    {
        $matcher = new CommandRuleMatcher();
        $rules = [new CommandRule('migrate', 'doctrine:migrations:migrate', RuleSource::Yaml)];

        $hit = $matcher->match('doctrine:migrations:migrate', $rules);

        self::assertNotNull($hit);
        self::assertSame('migrate', $hit->id);
    }

    public function testGlobMatch(): void
    {
        $matcher = new CommandRuleMatcher();
        $rules = [new CommandRule('msg', 'messenger:*', RuleSource::Yaml)];

        $hit = $matcher->match('messenger:consume', $rules);

        self::assertNotNull($hit);
        self::assertSame('msg', $hit->id);
    }

    public function testSingleCharGlobMatch(): void
    {
        $matcher = new CommandRuleMatcher();
        $rules = [new CommandRule('msg', 'app:cmd-?', RuleSource::Yaml)];

        self::assertNotNull($matcher->match('app:cmd-1', $rules));
        self::assertNull($matcher->match('app:cmd-12', $rules));
    }

    public function testNoMatchReturnsNull(): void
    {
        $matcher = new CommandRuleMatcher();
        $rules = [new CommandRule('msg', 'messenger:*', RuleSource::Yaml)];

        self::assertNull($matcher->match('app:something', $rules));
    }

    public function testFirstMatchWins(): void
    {
        $matcher = new CommandRuleMatcher();
        $rules = [
            new CommandRule('first', 'messenger:*', RuleSource::Yaml),
            new CommandRule('second', 'messenger:consume', RuleSource::Env),
        ];

        $hit = $matcher->match('messenger:consume', $rules);

        self::assertSame('first', $hit->id);
    }
}
