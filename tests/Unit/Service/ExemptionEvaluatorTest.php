<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;

final class ExemptionEvaluatorTest extends TestCase
{
    private function makeEvaluator(array $rules): ExemptionEvaluator
    {
        $router = $this->createStub(RequestMatcherInterface::class);
        return new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $router),
            new CommandRuleMatcher(),
            $rules,
        );
    }

    public function testEvaluateRequestReturnsMatchOnHit(): void
    {
        $eval = $this->makeEvaluator([
            new HttpRule('health', RuleSource::Yaml, pathGlob: '/health'),
        ]);

        $match = $eval->evaluateRequest(Request::create('/health'));

        self::assertNotNull($match);
        self::assertSame('health', $match->ruleId);
        self::assertSame(RuleSource::Yaml, $match->source);
    }

    public function testEvaluateRequestReturnsNullOnMiss(): void
    {
        $eval = $this->makeEvaluator([
            new HttpRule('health', RuleSource::Yaml, pathGlob: '/health'),
        ]);

        self::assertNull($eval->evaluateRequest(Request::create('/other')));
    }

    public function testEvaluateCommandReturnsMatch(): void
    {
        $eval = $this->makeEvaluator([
            new CommandRule('msg', 'messenger:*', RuleSource::Builtin),
        ]);

        $match = $eval->evaluateCommand('messenger:consume');

        self::assertNotNull($match);
        self::assertSame('msg', $match->ruleId);
        self::assertSame(RuleSource::Builtin, $match->source);
    }

    public function testEvaluateCommandReturnsNullOnMiss(): void
    {
        $eval = $this->makeEvaluator([
            new CommandRule('msg', 'messenger:*', RuleSource::Yaml),
        ]);

        self::assertNull($eval->evaluateCommand('app:other'));
    }

    public function testIpRuleViaRequestEvaluation(): void
    {
        $eval = $this->makeEvaluator([
            new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml),
        ]);

        $req = Request::create('/some');
        $req->server->set('REMOTE_ADDR', '10.5.4.3');

        $match = $eval->evaluateRequest($req);

        self::assertNotNull($match);
        self::assertSame('lan', $match->ruleId);
    }

    public function testEmptyRuleSet(): void
    {
        $eval = $this->makeEvaluator([]);

        self::assertNull($eval->evaluateRequest(Request::create('/any')));
        self::assertNull($eval->evaluateCommand('any:cmd'));
    }
}
