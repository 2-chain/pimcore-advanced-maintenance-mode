<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener;

use Pimcore\Security\User\User as PimcoreSecurityUser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\AdminBypassToken;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;

/**
 * Sets a long-lived signed bypass cookie whenever a Pimcore admin is authenticated,
 * so that the AdminSessionDetector can grant maintenance bypass even when the PHP
 * session cookie (SameSite=Strict) is absent on cross-site top-level navigations.
 */
final class AdminBypassCookieListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AdminBypassToken $bypassToken,
        private readonly BundleConfiguration $config,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            LogoutEvent::class => ['onLogout', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$this->config->bypassAuthenticatedAdmins) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof PimcoreSecurityUser) {
            return;
        }

        // Refresh the bypass cookie on every authenticated admin response.
        $cookieValue = $this->bypassToken->generate($user->getUserIdentifier());
        $event->getResponse()->headers->setCookie(new Cookie(
            AdminBypassToken::COOKIE_NAME,
            $cookieValue,
            time() + $this->bypassToken->ttl(),
            '/',
            null,
            null,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    public function onLogout(LogoutEvent $event): void
    {
        $response = $event->getResponse();
        if ($response !== null) {
            $response->headers->clearCookie(AdminBypassToken::COOKIE_NAME, '/');
        }
    }
}
