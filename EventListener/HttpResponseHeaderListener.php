<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\ExemptionMatch;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;

final class HttpResponseHeaderListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ActivationContext $context,
        private readonly BundleConfiguration $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $match = $request->attributes->get('_advanced_maintenance_match');
        if ($match instanceof ExemptionMatch) {
            $response->headers->set('X-Maintenance-Bypass', $match->ruleId);
            if (($reason = $this->context->getReason()) !== null) {
                $response->headers->set('X-Maintenance-Reason', $reason);
            }
            return;
        }

        if ($request->attributes->get('_advanced_maintenance_active') !== true) {
            return;
        }

        $retryAfter = $this->context->getRetryAfter() ?? $this->config->defaultRetryAfter;
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }
        if (($reason = $this->context->getReason()) !== null) {
            $response->headers->set('X-Maintenance-Reason', $reason);
        }
    }
}
