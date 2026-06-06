<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener;

use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\ExemptionMatch;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\AdminSessionDetectorInterface;

final class HttpExemptionListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ExemptionEvaluator             $evaluator,
        private readonly AdminSessionDetectorInterface  $adminDetector,
        private readonly BundleConfiguration            $config,
        private readonly ActivationContext              $context,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 127: between SessionListener (128) and Pimcore's
        // MaintenancePageListener (126).
        return [KernelEvents::REQUEST => ['onKernelRequest', 127]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $sessionId = null;
        if ($request->hasSession()) {
            $sessionId = $request->getSession()->getId();
        }

        if (!$this->helper->isActive($sessionId)) {
            return;
        }

        if ($this->config->bypassAuthenticatedAdmins) {
            if ($this->isAdminLoginPath($request)) {
                $request->attributes->set(
                    '_advanced_maintenance_match',
                    new ExemptionMatch('admin-login', RuleSource::Builtin, 'Pimcore admin login path'),
                );
                return;
            }
            if ($this->adminDetector->isLoggedInAdmin($request)) {
                $request->attributes->set(
                    '_advanced_maintenance_match',
                    new ExemptionMatch('admin-session', RuleSource::Builtin, 'authenticated Pimcore admin'),
                );
                return;
            }
        }

        $match = $this->evaluator->evaluateRequest($request);
        if ($match !== null) {
            $request->attributes->set('_advanced_maintenance_match', $match);
            return;
        }

        // Scope check: only activate maintenance if request matches the current scope.
        $contextScope = $this->context->getScope();
        if ($contextScope !== null && !$contextScope->isGlobal()) {
            $currentSiteId = null;
            if (\class_exists(\Pimcore\Tool\Frontend::class) && \method_exists(\Pimcore\Tool\Frontend::class, 'getSiteForRequest')) {
                $site = \Pimcore\Tool\Frontend::getSiteForRequest($request);
                $currentSiteId = $site?->getId();
            }
            if (!$contextScope->matchesRequest($request, $currentSiteId)) {
                return;
            }
        }

        $request->attributes->set('_advanced_maintenance_active', true);
        // fall through → Pimcore's listener at priority 126 sets the 503
    }

    private function isAdminLoginPath(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/admin/login');
    }
}
