<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures;

use Symfony\Component\Routing\Annotation\Route;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Attribute\ExemptFromMaintenance;

final class ExemptController
{
    #[Route(path: '/api/orders', name: 'app_api_orders')]
    #[ExemptFromMaintenance(id: 'orders-list')]
    public function list(): void {}

    #[Route(path: '/api/internal/x')]   // no name => path fallback
    #[ExemptFromMaintenance]
    public function internalOnly(): void {}
}
