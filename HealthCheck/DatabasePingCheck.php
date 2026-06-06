<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck;

use Doctrine\DBAL\Connection;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckInterface;

final class DatabasePingCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $connectionName,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'database_ping:' . $this->connectionName;
    }

    #[\Override]
    public function run(): HealthCheckResult
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            return new HealthCheckResult(
                passed: false,
                checkName: $this->getName(),
                errorMessage: 'Database ping failed: ' . $e->getMessage(),
            );
        }

        return new HealthCheckResult(
            passed: true,
            checkName: $this->getName(),
        );
    }
}
