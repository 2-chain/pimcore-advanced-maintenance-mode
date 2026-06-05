<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\ScheduleCancelCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;

final class ScheduleCancelCommandTest extends TestCase
{
    public function testCancelRemovesWindow(): void
    {
        $storage = $this->createMock(ScheduleStorage::class);
        $storage->method('findById')->with('w1')->willReturn(
            new ScheduleWindow('w1', 'UTC', null,
                new \DateTimeImmutable('2026-06-03T02:00:00Z'),
                new \DateTimeImmutable('2026-06-03T04:00:00Z'),
                null, null)
        );
        $storage->expects(self::once())->method('remove')->with('w1');

        $queue = $this->createMock(QueuedWindowStorageInterface::class);
        $queue->expects(self::once())->method('remove')->with('w1');

        $tester = new CommandTester(new ScheduleCancelCommand($storage, $queue));
        $tester->execute(['id' => 'w1']);

        $tester->assertCommandIsSuccessful();
    }

    public function testCancelUnknownIdFails(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findById')->willReturn(null);
        $queue = $this->createStub(QueuedWindowStorageInterface::class);

        $tester = new CommandTester(new ScheduleCancelCommand($storage, $queue));
        $result = $tester->execute(['id' => 'missing']);

        self::assertSame(1, $result);
    }
}
