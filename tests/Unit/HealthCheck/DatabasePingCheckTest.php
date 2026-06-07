<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\HealthCheck;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\DatabasePingCheck;
use RuntimeException;

final class DatabasePingCheckTest extends TestCase
{
    public function testGetNameIncludesConnectionName(): void
    {
        $connection = $this->createMock(Connection::class);
        $check = new DatabasePingCheck(connection: $connection, connectionName: 'default');

        self::assertSame('database_ping:default', $check->getName());
    }

    public function testPassesWhenSelectOneSucceeds(): void
    {
        $result = $this->createMock(Result::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willReturn($result);

        $check = new DatabasePingCheck(connection: $connection, connectionName: 'default');

        $checkResult = $check->run();

        self::assertTrue($checkResult->passed);
        self::assertNull($checkResult->errorMessage);
    }

    public function testFailsWhenConnectionThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->willThrowException(new RuntimeException('Connection refused'));

        $check = new DatabasePingCheck(connection: $connection, connectionName: 'default');

        $checkResult = $check->run();

        self::assertFalse($checkResult->passed);
        self::assertStringContainsString('Connection refused', $checkResult->errorMessage ?? '');
    }
}
