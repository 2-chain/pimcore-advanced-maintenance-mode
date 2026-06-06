<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\AdminSessionDetectorInterface;

final class StubAdminSessionDetector implements AdminSessionDetectorInterface
{
    public bool $isAdmin = false;

    public function isLoggedInAdmin(Request $request): bool
    {
        return $this->isAdmin || $request->headers->has('X-Test-Is-Admin');
    }
}
