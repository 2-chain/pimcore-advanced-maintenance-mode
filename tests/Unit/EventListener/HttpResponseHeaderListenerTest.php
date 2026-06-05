<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener\HttpResponseHeaderListener;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\ExemptionMatch;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;

final class HttpResponseHeaderListenerTest extends TestCase
{
    private function fakeContext(?string $reason, ?int $retryAfter): ActivationContext
    {
        $storage = new class ($reason, $retryAfter) implements ContextStorageInterface {
            public function __construct(private readonly ?string $reason, private readonly ?int $retryAfter) {}
            public function load(): array
            {
                return ['reason' => $this->reason, 'retry_after' => $this->retryAfter];
            }
            public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
            ): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    private function makeConfig(bool $bypass = true, ?int $retryAfter = 300): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: $bypass,
            defaultRetryAfter: $retryAfter,
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
            notificationWebhooks: [],
        );
    }

    private function makeEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }

    public function testNoOpWhenSubRequest(): void
    {
        $listener = new HttpResponseHeaderListener(
            $this->fakeContext(null, null),
            $this->makeConfig(),
        );
        $request = Request::create('/');
        $request->attributes->set('_advanced_maintenance_active', true);
        $response = new Response('', 503);
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testBypassHeaderOnExemptionMatch(): void
    {
        $listener = new HttpResponseHeaderListener(
            $this->fakeContext('migration', null),
            $this->makeConfig(),
        );
        $request = Request::create('/');
        $request->attributes->set(
            '_advanced_maintenance_match',
            new ExemptionMatch('health', RuleSource::Yaml, 'matched /health'),
        );
        $response = new Response('OK', 200);
        $event = $this->makeEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertSame('health', $response->headers->get('X-Maintenance-Bypass'));
        self::assertSame('migration', $response->headers->get('X-Maintenance-Reason'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testRetryAfterAddedOnActiveNoMatch(): void
    {
        $listener = new HttpResponseHeaderListener(
            $this->fakeContext('migration', 600),
            $this->makeConfig(),
        );
        $request = Request::create('/');
        $request->attributes->set('_advanced_maintenance_active', true);
        $response = new Response('Maintenance', 503);
        $event = $this->makeEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertSame('600', $response->headers->get('Retry-After'));
        self::assertSame('migration', $response->headers->get('X-Maintenance-Reason'));
    }

    public function testRetryAfterFallsBackToDefault(): void
    {
        $listener = new HttpResponseHeaderListener(
            $this->fakeContext(null, null),
            $this->makeConfig(),
        );
        $request = Request::create('/');
        $request->attributes->set('_advanced_maintenance_active', true);
        $response = new Response('Maintenance', 503);
        $event = $this->makeEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertSame('300', $response->headers->get('Retry-After'));
        self::assertFalse($response->headers->has('X-Maintenance-Reason'));
    }

    public function testRetryAfterOmittedWhenDefaultIsNull(): void
    {
        $listener = new HttpResponseHeaderListener(
            $this->fakeContext(null, null),
            $this->makeConfig(retryAfter: null),
        );
        $request = Request::create('/');
        $request->attributes->set('_advanced_maintenance_active', true);
        $response = new Response('Maintenance', 503);
        $event = $this->makeEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testNoAttributesNoHeaders(): void
    {
        $listener = new HttpResponseHeaderListener(
            $this->fakeContext('x', 60),
            $this->makeConfig(),
        );
        $request = Request::create('/');
        $response = new Response('OK', 200);
        $event = $this->makeEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertFalse($response->headers->has('Retry-After'));
        self::assertFalse($response->headers->has('X-Maintenance-Bypass'));
        self::assertFalse($response->headers->has('X-Maintenance-Reason'));
    }
}
