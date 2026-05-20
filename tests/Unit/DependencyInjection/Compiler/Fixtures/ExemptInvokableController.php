<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\DependencyInjection\Compiler\Fixtures;

use Symfony\Component\Routing\Annotation\Route;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Attribute\ExemptFromMaintenance;

#[Route(path: '/api/webhooks/orders', name: 'app_webhooks_orders', methods: ['POST'])]
#[ExemptFromMaintenance(id: 'order-webhook')]
final class ExemptInvokableController
{
    public function __invoke(): void {}
}
