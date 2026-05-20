<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener\HttpExemptionListener;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\AdminSessionDetectorInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\IpRuleMatcher;

final class HttpExemptionListenerTest extends TestCase
{
    private function makeRequest(string $path = '/'): Request
    {
        $req = Request::create($path);
        $session = new Session(new MockArraySessionStorage());
        $session->setId('test-session-id');
        $req->setSession($session);

        return $req;
    }

    private function makeEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function makeListener(
        bool $isActive,
        array $rules = [],
        bool $isAdmin = false,
        bool $bypassAdmins = true,
    ): HttpExemptionListener {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn($isActive);

        $admin = new class ($isAdmin) implements AdminSessionDetectorInterface {
            public function __construct(private bool $is) {}
            public function isLoggedInAdmin(Request $request): bool
            {
                return $this->is;
            }
        };

        $evaluator = new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $this->createStub(RequestMatcherInterface::class)),
            new CommandRuleMatcher(),
            $rules,
        );

        return new HttpExemptionListener(
            helper: $helper,
            evaluator: $evaluator,
            adminDetector: $admin,
            config: new BundleConfiguration(bypassAuthenticatedAdmins: $bypassAdmins, defaultRetryAfter: 300),
        );
    }

    public function testNoOpWhenSubRequest(): void
    {
        $listener = $this->makeListener(isActive: true);
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $this->makeRequest(),
            HttpKernelInterface::SUB_REQUEST,
        );

        $listener->onKernelRequest($event);

        self::assertFalse($event->isPropagationStopped());
    }

    public function testNoOpWhenMaintenanceModeOff(): void
    {
        $listener = $this->makeListener(isActive: false);
        $event = $this->makeEvent($this->makeRequest());

        $listener->onKernelRequest($event);

        self::assertFalse($event->isPropagationStopped());
    }

    public function testAdminBypassMatchesWhenEnabled(): void
    {
        $listener = $this->makeListener(isActive: true, isAdmin: true, bypassAdmins: true);
        $request = $this->makeRequest();
        $event = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        // Propagation must NOT be stopped — downstream listeners (router, controller) must still fire.
        self::assertFalse($event->isPropagationStopped());
        $match = $request->attributes->get('_advanced_maintenance_match');
        self::assertNotNull($match);
        self::assertSame('admin-session', $match->ruleId);
    }

    public function testAdminBypassIgnoredWhenDisabled(): void
    {
        $listener = $this->makeListener(isActive: true, isAdmin: true, bypassAdmins: false);
        $request = $this->makeRequest();
        $event = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertFalse($event->isPropagationStopped());
        self::assertNull($request->attributes->get('_advanced_maintenance_match'));
        self::assertTrue($request->attributes->get('_advanced_maintenance_active'));
    }

    public function testHttpRuleMatchSetsMatchAttributeWithoutStoppingPropagation(): void
    {
        $listener = $this->makeListener(isActive: true, rules: [
            new HttpRule('health', RuleSource::Yaml, pathGlob: '/health'),
        ]);
        $request = $this->makeRequest('/health');
        $event = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        // Propagation must NOT be stopped — downstream listeners (router, controller) must still fire.
        self::assertFalse($event->isPropagationStopped());
        $match = $request->attributes->get('_advanced_maintenance_match');
        self::assertSame('health', $match->ruleId);
    }

    public function testNoMatchSetsActiveAttribute(): void
    {
        $listener = $this->makeListener(isActive: true);
        $request = $this->makeRequest('/admin');
        $event = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertFalse($event->isPropagationStopped());
        self::assertNull($request->attributes->get('_advanced_maintenance_match'));
        self::assertTrue($request->attributes->get('_advanced_maintenance_active'));
    }
}
