<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces;

use Symfony\Component\HttpFoundation\Request;

interface AdminSessionDetectorInterface
{
    public function isLoggedInAdmin(Request $request): bool;
}
