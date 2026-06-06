<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerRenderer;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\PreAnnounceBannerProvider;

final class PreAnnounceHtmlInjectionListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly BundleConfiguration $config,
        private readonly PreAnnounceBannerProvider $provider,
        private readonly PreAnnounceBannerRenderer $renderer,
    ) {}

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->config->autoInjectBanner) {
            return;
        }
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        if (!\str_starts_with($response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }
        if ($response->isRedirect()) {
            return;
        }
        if ($this->provider->wasRendered()) {
            return;
        }
        $data = $this->provider->provide();
        if ($data === null) {
            return;
        }

        $nonce   = $this->extractScriptNonce($response->headers->get('Content-Security-Policy', ''));
        $html    = $this->renderer->render($data, $nonce);
        $content = $response->getContent();
        if (!\is_string($content)) {
            return;
        }
        $pos = \strrpos($content, '</body>');
        if ($pos === false) {
            return;
        }
        $content = \substr($content, 0, $pos) . $html . \substr($content, $pos);
        $response->setContent($content);
        $this->provider->markRendered();
    }

    private function extractScriptNonce(string $csp): ?string
    {
        // CSP level 2+: when a nonce is present, 'unsafe-inline' is ignored by
        // modern browsers — our <script> must carry the same nonce to execute.
        // Match the script-src directive, then pull the first nonce value.
        if (\preg_match("/\bscript-src\b[^;]*'nonce-([^']+)'/", $csp, $m)) {
            return $m[1];
        }
        return null;
    }
}
