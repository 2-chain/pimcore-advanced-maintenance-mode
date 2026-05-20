<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures\InMemoryMaintenanceModeHelper;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures\StubAdminSessionDetector;

final class HttpExemptionTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function bootKernel(array $config = []): TestKernel
    {
        $kernel = new TestKernel('test', false, $config);
        $kernel->boot();
        return $kernel;
    }

    public function testMaintenanceOffReturns200(): void
    {
        $kernel = $this->bootKernel();

        $response = $kernel->handle(Request::create('/health'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertFalse($response->headers->has('X-Maintenance-Bypass'));
    }

    public function testMaintenanceOnNoMatchReturns503WithRetryAfter(): void
    {
        $kernel = $this->bootKernel();
        /** @var InMemoryMaintenanceModeHelper $helper */
        $helper = $kernel->getContainer()->get(InMemoryMaintenanceModeHelper::class);
        $helper->activate('activator-session');

        $response = $kernel->handle(Request::create('/admin/area'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('300', $response->headers->get('Retry-After'));
    }

    public function testExemptRouteReturns200WithBypassHeader(): void
    {
        $kernel = $this->bootKernel([
            'exemptions' => ['routes' => [['path' => '/health', 'id' => 'health']]],
        ]);
        /** @var InMemoryMaintenanceModeHelper $helper */
        $helper = $kernel->getContainer()->get(InMemoryMaintenanceModeHelper::class);
        $helper->activate('activator');

        $response = $kernel->handle(Request::create('/health'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('health', $response->headers->get('X-Maintenance-Bypass'));
    }

    public function testExemptIpReturns200(): void
    {
        $kernel = $this->bootKernel([
            'exemptions' => ['ips' => ['10.0.0.0/8']],
        ]);
        /** @var InMemoryMaintenanceModeHelper $helper */
        $helper = $kernel->getContainer()->get(InMemoryMaintenanceModeHelper::class);
        $helper->activate('activator');

        $request = Request::create('/admin/area');
        $request->server->set('REMOTE_ADDR', '10.5.4.3');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('X-Maintenance-Bypass'));
    }

    public function testAdminSessionBypass(): void
    {
        $kernel = $this->bootKernel();
        /** @var InMemoryMaintenanceModeHelper $helper */
        $helper = $kernel->getContainer()->get(InMemoryMaintenanceModeHelper::class);
        $helper->activate('activator');
        /** @var StubAdminSessionDetector $detector */
        $detector = $kernel->getContainer()->get(StubAdminSessionDetector::class);
        $detector->isAdmin = true;

        $response = $kernel->handle(Request::create('/admin/area'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin-session', $response->headers->get('X-Maintenance-Bypass'));
    }

    public function testActivationReasonInResponseHeader(): void
    {
        $kernel = $this->bootKernel();
        /** @var InMemoryMaintenanceModeHelper $helper */
        $helper = $kernel->getContainer()->get(InMemoryMaintenanceModeHelper::class);
        $helper->activate('activator');

        /** @var ActivationContext $ctx */
        $ctx = $kernel->getContainer()->get(ActivationContext::class);
        $ctx->set('DB migration v3.5', 600);

        $response = $kernel->handle(Request::create('/admin/area'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('DB migration v3.5', $response->headers->get('X-Maintenance-Reason'));
        self::assertSame('600', $response->headers->get('Retry-After'));
    }
}
