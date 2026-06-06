<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckRunnerInterface;

final class HealthCheckRunner implements HealthCheckRunnerInterface
{
    /**
     * @param HealthCheckInterface[] $checks
     */
    public function __construct(private readonly array $checks) {}

    /**
     * @return HealthCheckResult[]
     */
    public function runAll(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            $results[] = $check->run();
        }

        return $results;
    }

    /**
     * @param HealthCheckResult[] $results
     */
    public function allPassed(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->passed) {
                return false;
            }
        }

        return true;
    }
}
