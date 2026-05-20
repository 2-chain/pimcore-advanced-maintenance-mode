<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service\Matcher;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;

final class HttpRuleMatcherTest extends TestCase
{
    private function makeMatcher(): HttpRuleMatcher
    {
        return new HttpRuleMatcher(
            new IpRuleMatcher(),
            $this->createStub(RequestMatcherInterface::class),
        );
    }

    public function testPathGlobMatch(): void
    {
        $matcher = $this->makeMatcher();
        $req = Request::create('/health');
        $rules = [new HttpRule('health', RuleSource::Yaml, pathGlob: '/health')];

        $hit = $matcher->matchRequest($req, $rules, []);
        self::assertNotNull($hit);
        self::assertSame('health', $hit->id);
    }

    public function testPathGlobWildcard(): void
    {
        $matcher = $this->makeMatcher();
        $req = Request::create('/api/webhooks/orders');
        $rules = [new HttpRule('webhooks', RuleSource::Yaml, pathGlob: '/api/webhooks/*')];

        self::assertNotNull($matcher->matchRequest($req, $rules, []));
    }

    public function testMethodFilter(): void
    {
        $matcher = $this->makeMatcher();
        $rules = [new HttpRule('post-only', RuleSource::Yaml, pathGlob: '/api/*', methods: ['POST'])];

        $get = Request::create('/api/x', 'GET');
        $post = Request::create('/api/x', 'POST');

        self::assertNull($matcher->matchRequest($get, $rules, []));
        self::assertNotNull($matcher->matchRequest($post, $rules, []));
    }

    public function testHostMatch(): void
    {
        $matcher = $this->makeMatcher();
        $req = Request::create('https://api.example.com/anything');
        $rules = [new HttpRule('api-host', RuleSource::Yaml, host: 'api.example.com')];

        self::assertNotNull($matcher->matchRequest($req, $rules, []));
    }

    public function testHostGlob(): void
    {
        $matcher = $this->makeMatcher();
        $req = Request::create('https://api.example.com/x');
        $rules = [new HttpRule('api-host', RuleSource::Yaml, host: 'api.*')];

        self::assertNotNull($matcher->matchRequest($req, $rules, []));
    }

    public function testCombinedFieldsAreAnded(): void
    {
        $matcher = $this->makeMatcher();
        $rules = [
            new HttpRule('strict', RuleSource::Yaml, pathGlob: '/admin/*', host: 'admin.example.com', methods: ['GET']),
        ];

        $wrongHost = Request::create('https://other.example.com/admin/users', 'GET');
        $wrongMethod = Request::create('https://admin.example.com/admin/users', 'POST');
        $allMatch = Request::create('https://admin.example.com/admin/users', 'GET');

        self::assertNull($matcher->matchRequest($wrongHost, $rules, []));
        self::assertNull($matcher->matchRequest($wrongMethod, $rules, []));
        self::assertNotNull($matcher->matchRequest($allMatch, $rules, []));
    }

    public function testRouteNameMatchFromRequestAttribute(): void
    {
        $matcher = $this->makeMatcher();
        $req = Request::create('/foo');
        $req->attributes->set('_route', 'app_api_orders');
        $rules = [new HttpRule('orders', RuleSource::Yaml, routeName: 'app_api_orders')];

        self::assertNotNull($matcher->matchRequest($req, $rules, []));
    }

    public function testIpRulesShortCircuit(): void
    {
        $matcher = $this->makeMatcher();
        $req = Request::create('/blocked');
        $req->server->set('REMOTE_ADDR', '10.5.4.3');
        $httpRules = [new HttpRule('x', RuleSource::Yaml, pathGlob: '/something-else')];
        $ipRules = [new IpRule('lan', '10.0.0.0/8', RuleSource::Yaml)];

        $hit = $matcher->matchRequest($req, $httpRules, $ipRules);

        self::assertNotNull($hit);
        self::assertSame('lan', $hit->id);
    }

    public function testRouteResolvedLazilyViaRouterWhenAttributeMissing(): void
    {
        $router = $this->createMock(RequestMatcherInterface::class);
        $router->expects(self::once())
            ->method('matchRequest')
            ->willReturn(['_route' => 'app_resolved']);

        $matcher = new HttpRuleMatcher(new IpRuleMatcher(), $router);
        $req = Request::create('/lazy');
        $rules = [new HttpRule('lazy', RuleSource::Yaml, routeName: 'app_resolved')];

        self::assertNotNull($matcher->matchRequest($req, $rules, []));
    }

    public function testRouterNotFoundDoesNotThrow(): void
    {
        $router = $this->createStub(RequestMatcherInterface::class);
        $router->method('matchRequest')->willThrowException(new ResourceNotFoundException());

        $matcher = new HttpRuleMatcher(new IpRuleMatcher(), $router);
        $req = Request::create('/missing');
        $rules = [new HttpRule('x', RuleSource::Yaml, routeName: 'something')];

        self::assertNull($matcher->matchRequest($req, $rules, []));
    }

    public function testRouteResolutionCachedAcrossRulesInSameRequest(): void
    {
        $router = $this->createMock(RequestMatcherInterface::class);
        $router->expects(self::once())
            ->method('matchRequest')
            ->willReturn(['_route' => 'app_x']);

        $matcher = new HttpRuleMatcher(new IpRuleMatcher(), $router);
        $req = Request::create('/x');
        $rules = [
            new HttpRule('a', RuleSource::Yaml, routeName: 'app_y'),
            new HttpRule('b', RuleSource::Yaml, routeName: 'app_x'),
        ];

        $matcher->matchRequest($req, $rules, []);
    }
}
