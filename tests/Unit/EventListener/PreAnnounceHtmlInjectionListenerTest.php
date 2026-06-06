<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener\PreAnnounceHtmlInjectionListener;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerRenderer;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\PreAnnounceBannerProvider;

final class PreAnnounceHtmlInjectionListenerTest extends TestCase
{
    private function makeConfig(bool $autoInject = true): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: $autoInject,
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
        );
    }

    private function makeProvider(?PreAnnounceData $data, bool $alreadyRendered = false): PreAnnounceBannerProvider
    {
        return new class($data, $alreadyRendered) extends PreAnnounceBannerProvider {
            public function __construct(
                private readonly ?PreAnnounceData $mockData,
                private bool $mockRendered,
            ) {}
            public function provide(): ?PreAnnounceData { return $this->mockData; }
            public function wasRendered(): bool { return $this->mockRendered; }
            public function markRendered(): void { $this->mockRendered = true; }
        };
    }

    private function makeRenderer(?string &$capturedNonce = null): PreAnnounceBannerRenderer
    {
        return new class($capturedNonce) extends PreAnnounceBannerRenderer {
            public function __construct(private mixed &$capture) {}
            public function render(PreAnnounceData $data, ?string $nonce = null): string
            {
                $this->capture = $nonce;
                return '<div id="amm-banner">BANNER</div>';
            }
        };
    }

    private function makeEvent(string $body, string $contentType = 'text/html', bool $mainRequest = true): ResponseEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $response = new Response($body, 200, ['Content-Type' => $contentType]);
        return new ResponseEvent(
            $kernel,
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response,
        );
    }

    public function testInjectsBannerBeforeClosingBody(): void
    {
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider($data),
            $this->makeRenderer(),
        );
        $event = $this->makeEvent('<html><body><p>Hello</p></body></html>');
        $listener->onKernelResponse($event);

        self::assertStringContainsString('<div id="amm-banner">BANNER</div></body>', $event->getResponse()->getContent());
    }

    public function testSkipsWhenAutoInjectDisabled(): void
    {
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(false),
            $this->makeProvider($data),
            $this->makeRenderer(),
        );
        $event = $this->makeEvent('<html><body></body></html>');
        $listener->onKernelResponse($event);

        self::assertStringNotContainsString('amm-banner', $event->getResponse()->getContent());
    }

    public function testSkipsNonHtmlResponse(): void
    {
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider($data),
            $this->makeRenderer(),
        );
        $event = $this->makeEvent('{"ok":true}', 'application/json');
        $listener->onKernelResponse($event);

        self::assertStringNotContainsString('amm-banner', $event->getResponse()->getContent());
    }

    public function testSkipsSubRequest(): void
    {
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider($data),
            $this->makeRenderer(),
        );
        $event = $this->makeEvent('<html><body></body></html>', 'text/html', false);
        $listener->onKernelResponse($event);

        self::assertStringNotContainsString('amm-banner', $event->getResponse()->getContent());
    }

    public function testSkipsWhenAlreadyRendered(): void
    {
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider($data, alreadyRendered: true),
            $this->makeRenderer(),
        );
        $event = $this->makeEvent('<html><body></body></html>');
        $listener->onKernelResponse($event);

        self::assertStringNotContainsString('amm-banner', $event->getResponse()->getContent());
    }

    public function testSkipsWhenNoData(): void
    {
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider(null),
            $this->makeRenderer(),
        );
        $event = $this->makeEvent('<html><body></body></html>');
        $listener->onKernelResponse($event);

        self::assertStringNotContainsString('amm-banner', $event->getResponse()->getContent());
    }

    public function testInjectsBeforeLastBodyTag(): void
    {
        // Two </body> tags — must replace last one only
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider($data),
            $this->makeRenderer(),
        );
        $event = $this->makeEvent('<frame></body><html><body>content</body></html>');
        $listener->onKernelResponse($event);

        $content = $event->getResponse()->getContent();
        // Banner appears right before the LAST </body>
        self::assertStringEndsWith('<div id="amm-banner">BANNER</div></body></html>', $content);
    }

    public function testExtractsNonceFromCspAndPassesToRenderer(): void
    {
        $capturedNonce = null;
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider($data),
            $this->makeRenderer($capturedNonce),
        );
        $kernel   = $this->createStub(HttpKernelInterface::class);
        $request  = Request::create('/');
        $response = new Response('<html><body></body></html>', 200, [
            'Content-Type'            => 'text/html',
            'Content-Security-Policy' => "script-src 'self' 'nonce-abc123' 'unsafe-inline'",
        ]);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $listener->onKernelResponse($event);

        self::assertSame('abc123', $capturedNonce);
    }

    public function testPassesNullNonceWhenNoCspHeader(): void
    {
        $capturedNonce = 'initial';
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        $listener = new PreAnnounceHtmlInjectionListener(
            $this->makeConfig(true),
            $this->makeProvider($data),
            $this->makeRenderer($capturedNonce),
        );
        $event = $this->makeEvent('<html><body></body></html>');
        $listener->onKernelResponse($event);

        self::assertNull($capturedNonce);
    }
}
