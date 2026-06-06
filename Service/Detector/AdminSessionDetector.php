<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Detector;

use Override;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\AdminSessionDetectorInterface;

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
