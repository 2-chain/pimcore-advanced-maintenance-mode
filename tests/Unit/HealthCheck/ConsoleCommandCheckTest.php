<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\HealthCheck;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\ConsoleCommandCheck;

final class ConsoleCommandCheckTest extends TestCase
{
    public function testGetNameIncludesCommand(): void
    {
        $check = new ConsoleCommandCheck(
            command: 'app:health-check',
            timeoutSeconds: 60,
            processFactory: fn(array $cmd, int $timeout) => $this->makeProcess(0, ''),
        );

        self::assertSame('console_command:app:health-check', $check->getName());
    }

    public function testPassesWhenProcessExitsWithZero(): void
    {
        $check = new ConsoleCommandCheck(
            command: 'app:health-check',
            timeoutSeconds: 60,
            processFactory: fn(array $cmd, int $timeout) => $this->makeProcess(0, ''),
        );

        $result = $check->run();

        self::assertTrue($result->passed);
        self::assertNull($result->errorMessage);
    }

    public function testFailsWhenProcessExitsNonZero(): void
    {
        $check = new ConsoleCommandCheck(
            command: 'app:health-check',
            timeoutSeconds: 60,
            processFactory: fn(array $cmd, int $timeout) => $this->makeProcess(1, 'health check failed'),
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('exit code 1', $result->errorMessage ?? '');
        self::assertStringContainsString('health check failed', $result->errorMessage ?? '');
    }

    public function testFailsWhenProcessThrows(): void
    {
        $check = new ConsoleCommandCheck(
            command: 'app:health-check',
            timeoutSeconds: 60,
            processFactory: function (array $cmd, int $timeout): Process {
                throw new \RuntimeException('Process could not be started');
            },
        );

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('Process could not be started', $result->errorMessage ?? '');
    }

    /**
     * Creates a stub Process that always returns the given exit code and output.
     */
    private function makeProcess(int $exitCode, string $errorOutput): Process
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willReturn($exitCode);
        $process->method('getExitCode')->willReturn($exitCode);
        $process->method('getErrorOutput')->willReturn($errorOutput);
        $process->method('isSuccessful')->willReturn($exitCode === 0);

        return $process;
    }
}
