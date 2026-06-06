<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\HealthCheck;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckResult;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckRunner;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\Interfaces\HealthCheckInterface;

final class HealthCheckRunnerTest extends TestCase
{
    public function testRunAllReturnsResultForEachCheck(): void
    {
        $check1 = $this->makeCheck('check-a', true);
        $check2 = $this->makeCheck('check-b', true);

        $runner = new HealthCheckRunner([$check1, $check2]);
        $results = $runner->runAll();

        self::assertCount(2, $results);
        self::assertSame('check-a', $results[0]->checkName);
        self::assertSame('check-b', $results[1]->checkName);
    }

    public function testRunAllContinuesAfterFailure(): void
    {
        $check1 = $this->makeCheck('failing-check', false, 'DB down');
        $check2 = $this->makeCheck('passing-check', true);

        $runner = new HealthCheckRunner([$check1, $check2]);
        $results = $runner->runAll();

        // Both checks ran even though first failed
        self::assertCount(2, $results);
        self::assertFalse($results[0]->passed);
        self::assertTrue($results[1]->passed);
    }

    public function testAllPassedReturnsTrueWhenAllPass(): void
    {
        $runner = new HealthCheckRunner([]);
        $results = [
            new HealthCheckResult(passed: true, checkName: 'a'),
            new HealthCheckResult(passed: true, checkName: 'b'),
        ];

        self::assertTrue($runner->allPassed($results));
    }

    public function testAllPassedReturnsFalseWhenAnyFails(): void
    {
        $runner = new HealthCheckRunner([]);
        $results = [
            new HealthCheckResult(passed: true, checkName: 'a'),
            new HealthCheckResult(passed: false, checkName: 'b', errorMessage: 'fail'),
        ];

        self::assertFalse($runner->allPassed($results));
    }

    public function testAllPassedReturnsTrueForEmptyResults(): void
    {
        $runner = new HealthCheckRunner([]);

        self::assertTrue($runner->allPassed([]));
    }

    public function testRunAllReturnsEmptyArrayWhenNoChecks(): void
    {
        $runner = new HealthCheckRunner([]);

        self::assertSame([], $runner->runAll());
    }

    private function makeCheck(string $name, bool $passes, string $error = ''): HealthCheckInterface
    {
        $result = new HealthCheckResult(
            passed: $passes,
            checkName: $name,
            errorMessage: $passes ? null : $error,
        );

        $check = $this->createMock(HealthCheckInterface::class);
        $check->method('getName')->willReturn($name);
        $check->method('run')->willReturn($result);

        return $check;
    }
}
