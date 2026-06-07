<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Detector;

use Override;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\AdminBypassToken;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\AdminSessionDetectorInterface;

final class AdminSessionDetector implements AdminSessionDetectorInterface
{
    public function __construct(private readonly ?AdminBypassToken $bypassToken = null) {}

    #[Override]
    public function isLoggedInAdmin(Request $request): bool
    {
        if (\class_exists(\Pimcore\Tool\Authentication::class)) {
            try {
                if (\Pimcore\Tool\Authentication::authenticateSession($request) !== null) {
                    return true;
                }
            } catch (Throwable) {
                // Session unavailable — fall through to cookie check
            }
        }

        // Fallback: HMAC bypass cookie for cross-site navigations where the PHP
        // session cookie (SameSite=Strict) is absent on top-level navigations.
        if ($this->bypassToken !== null) {
            $cookie = $request->cookies->get(AdminBypassToken::COOKIE_NAME);
            if ($cookie !== null) {
                return $this->bypassToken->verify($cookie) !== null;
            }
        }

        return false;
    }
}
