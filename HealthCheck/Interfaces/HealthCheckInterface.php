<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckResult;

interface HealthCheckInterface
{
    public function getName(): string;

    public function run(): HealthCheckResult;
}
