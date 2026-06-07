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
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\AdminSessionDetectorInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;
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
            $this->createStub(HttpKernelInterface::class),
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
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
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

        $noScopeStorage = new class implements ContextStorageInterface {
            public function load(): array
            {
                return ['reason' => null, 'retry_after' => null, 'scope' => null];
            }
            public function save(?string $reason, ?int $retryAfter, ?string $activatedByScheduleWindowId = null, ?string $expectedEndAt = null, bool $activatedByHealthCheckFailure = false, ?int $activatedByHistoryRecordId = null, ?string $expiresAt = null, ?int $originalTtlMinutes = null, ?string $warningEmittedAt = null): void {}
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };

        return new HttpExemptionListener(
            helper: $helper,
            evaluator: $evaluator,
            adminDetector: $admin,
            config: new BundleConfiguration(
                bypassAuthenticatedAdmins: $bypassAdmins,
                defaultRetryAfter: 300,
                defaultTtl: null,
                expiryWarningThreshold: null,
                publicStatusEnabled: false,
                publicStatusToken: null,
                autoInjectBanner: true,
                defaultThresholdMinutes: 60,
                urgencyOrangeMinutes: 30,
                urgencyRedMinutes: 10,
                dismissPersistence: 'session',
                mailOnPreAnnounce: false,
                mailOnMaintenanceStart: false,
                mailOnMaintenanceEnd: false,
                mailRecipients: [],
                mailOnPreAnnounceRecipients: [],
                mailOnMaintenanceStartRecipients: [],
                mailOnMaintenanceEndRecipients: [],
                mailTemplate: null,
                mailPreAnnounceTemplate: null,
                mailMaintenanceStartTemplate: null,
                mailMaintenanceEndTemplate: null,
                notificationWebhooks: [],
            ),
            context: new ActivationContext($noScopeStorage),
        );
    }

    public function testNoOpWhenSubRequest(): void
    {
        $listener = $this->makeListener(isActive: true);
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
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

    public function testAdminLoginPathIsExemptWhenBypassAdminsEnabled(): void
    {
        // bypassAuthenticatedAdmins=true, isAdmin=false (unauthenticated user on login page)
        $listener = $this->makeListener(isActive: true, isAdmin: false, bypassAdmins: true);
        $request = $this->makeRequest('/admin/login');
        $event = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertFalse($event->isPropagationStopped());
        $match = $request->attributes->get('_advanced_maintenance_match');
        self::assertNotNull($match);
        self::assertSame('admin-login', $match->ruleId);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminLoginPathVariantsProvider')]
    public function testAdminLoginSubPathsAreExemptWhenBypassAdminsEnabled(string $path): void
    {
        $listener = $this->makeListener(isActive: true, isAdmin: false, bypassAdmins: true);
        $request = $this->makeRequest($path);
        $event = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        $match = $request->attributes->get('_advanced_maintenance_match');
        self::assertNotNull($match);
        self::assertSame('admin-login', $match->ruleId);
    }

    public static function adminLoginPathVariantsProvider(): array
    {
        return [
            'login_check'  => ['/admin/login_check'],
            '2fa'          => ['/admin/login/2fa'],
            '2fa-verify'   => ['/admin/login/2fa-verify'],
        ];
    }

    public function testAdminLoginPathIsNotExemptWhenBypassAdminsDisabled(): void
    {
        // bypassAuthenticatedAdmins=false — the admin-login exemption must not apply
        $listener = $this->makeListener(isActive: true, isAdmin: false, bypassAdmins: false);
        $request = $this->makeRequest('/admin/login');
        $event = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertFalse($event->isPropagationStopped());
        self::assertNull($request->attributes->get('_advanced_maintenance_match'));
        self::assertTrue($request->attributes->get('_advanced_maintenance_active'));
    }

    private function makeListenerWithScope(
        bool              $isActive,
        ?MaintenanceScope $scope,
        array             $rules = [],
    ): HttpExemptionListener {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn($isActive);

        $admin = new class implements AdminSessionDetectorInterface {
            public function isLoggedInAdmin(Request $request): bool
            {
                return false;
            }
        };

        $evaluator = new ExemptionEvaluator(
            new HttpRuleMatcher(new IpRuleMatcher(), $this->createStub(RequestMatcherInterface::class)),
            new CommandRuleMatcher(),
            $rules,
        );

        $scopeForClosure = $scope;
        $storage = new class ($scopeForClosure) implements ContextStorageInterface {
            public function __construct(private readonly ?MaintenanceScope $sc) {}
            public function load(): array
            {
                return [
                    'reason'      => null,
                    'retry_after' => null,
                    'scope'       => $this->sc !== null
                        ? ['path_prefixes' => $this->sc->pathPrefixes, 'site_ids' => $this->sc->siteIds]
                        : null,
                ];
            }
            public function save(?string $reason, ?int $retryAfter, ?string $activatedByScheduleWindowId = null, ?string $expectedEndAt = null, bool $activatedByHealthCheckFailure = false, ?int $activatedByHistoryRecordId = null, ?string $expiresAt = null, ?int $originalTtlMinutes = null, ?string $warningEmittedAt = null): void {}
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };
        $context = new ActivationContext($storage);

        return new HttpExemptionListener(
            helper: $helper,
            evaluator: $evaluator,
            adminDetector: $admin,
            config: new BundleConfiguration(
                bypassAuthenticatedAdmins: false,
                defaultRetryAfter: 300,
                defaultTtl: null,
                expiryWarningThreshold: null,
                publicStatusEnabled: false,
                publicStatusToken: null,
                autoInjectBanner: true,
                defaultThresholdMinutes: 60,
                urgencyOrangeMinutes: 30,
                urgencyRedMinutes: 10,
                dismissPersistence: 'session',
                mailOnPreAnnounce: false,
                mailOnMaintenanceStart: false,
                mailOnMaintenanceEnd: false,
                mailRecipients: [],
                mailOnPreAnnounceRecipients: [],
                mailOnMaintenanceStartRecipients: [],
                mailOnMaintenanceEndRecipients: [],
                mailTemplate: null,
                mailPreAnnounceTemplate: null,
                mailMaintenanceStartTemplate: null,
                mailMaintenanceEndTemplate: null,
                notificationWebhooks: [],
            ),
            context: $context,
        );
    }

    public function testNullScopeActivatesGlobally(): void
    {
        $listener = $this->makeListenerWithScope(isActive: true, scope: null);
        $request  = $this->makeRequest('/blog');
        $event    = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertTrue($request->attributes->get('_advanced_maintenance_active'));
    }

    public function testGlobalScopeActivatesGlobally(): void
    {
        $listener = $this->makeListenerWithScope(isActive: true, scope: new MaintenanceScope([], []));
        $request  = $this->makeRequest('/blog');
        $event    = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertTrue($request->attributes->get('_advanced_maintenance_active'));
    }

    public function testPathScopeMatchActivatesMaintenance(): void
    {
        $listener = $this->makeListenerWithScope(isActive: true, scope: new MaintenanceScope(['/shop'], []));
        $request  = $this->makeRequest('/shop/product/123');
        $event    = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertTrue($request->attributes->get('_advanced_maintenance_active'));
    }

    public function testPathScopeNoMatchPassesThrough(): void
    {
        $listener = $this->makeListenerWithScope(isActive: true, scope: new MaintenanceScope(['/shop'], []));
        $request  = $this->makeRequest('/blog/post');
        $event    = $this->makeEvent($request);

        $listener->onKernelRequest($event);

        self::assertNull($request->attributes->get('_advanced_maintenance_active'));
        self::assertNull($request->attributes->get('_advanced_maintenance_match'));
    }
}
