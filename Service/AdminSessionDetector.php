<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Override;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final class AdminSessionDetector implements AdminSessionDetectorInterface
{
    #[Override]
    public function isLoggedInAdmin(Request $request): bool
    {
        if (!\class_exists(\Pimcore\Tool\Authentication::class)) {
            return false;
        }

        try {
            return \Pimcore\Tool\Authentication::authenticateSession($request) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
