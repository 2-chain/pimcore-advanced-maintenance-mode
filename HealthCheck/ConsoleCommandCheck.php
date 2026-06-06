<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck;

use Symfony\Component\Process\Process;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckInterface;

final class ConsoleCommandCheck implements HealthCheckInterface
{
    /**
     * @param callable(array<int,string>, int): Process|null $processFactory
     */
    public function __construct(
        private readonly string $command,
        private readonly int $timeoutSeconds,
        private readonly mixed $processFactory = null,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'console_command:' . $this->command;
    }

    #[\Override]
    public function run(): HealthCheckResult
    {
        $cmd = \array_filter(\explode(' ', $this->command));

        try {
            $factory = $this->processFactory ?? fn(array $c, int $t): Process => new Process($c, timeout: $t);
            $process = ($factory)(\array_values($cmd), $this->timeoutSeconds);
            $process->run();
        } catch (\Throwable $e) {
            return new HealthCheckResult(
                passed: false,
                checkName: $this->getName(),
                errorMessage: 'Failed to run process: ' . $e->getMessage(),
            );
        }

        if (!$process->isSuccessful()) {
            return new HealthCheckResult(
                passed: false,
                checkName: $this->getName(),
                errorMessage: \sprintf(
                    'Command "%s" exited with exit code %d: %s',
                    $this->command,
                    $process->getExitCode() ?? -1,
                    \trim($process->getErrorOutput()),
                ),
            );
        }

        return new HealthCheckResult(
            passed: true,
            checkName: $this->getName(),
        );
    }
}
