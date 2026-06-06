<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckResult;

interface HealthCheckRunnerInterface
{
    /**
     * @return HealthCheckResult[]
     */
    public function runAll(): array;

    /**
     * @param HealthCheckResult[] $results
     */
    public function allPassed(array $results): bool;
}
