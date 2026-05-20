<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Symfony\Component\HttpFoundation\Request;

interface AdminSessionDetectorInterface
{
    public function isLoggedInAdmin(Request $request): bool;
}
