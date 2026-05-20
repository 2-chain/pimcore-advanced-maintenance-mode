<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TestController
{
    #[Route('/health', name: 'app_health')]
    public function health(): Response
    {
        return new Response('healthy', 200);
    }

    #[Route('/admin/area', name: 'app_admin_area')]
    public function admin(): Response
    {
        return new Response('admin', 200);
    }
}
