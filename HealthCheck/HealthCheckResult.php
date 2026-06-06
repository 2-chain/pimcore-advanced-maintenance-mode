<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck;

final class HealthCheckResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly string $checkName,
        public readonly ?string $errorMessage = null,
    ) {}
}
