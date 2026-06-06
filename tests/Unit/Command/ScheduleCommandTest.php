<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\ScheduleCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Detector\OverlapDetector;

final class ScheduleCommandTest extends TestCase
{
    private function storage(): ScheduleStorage
    {
        return new class extends ScheduleStorage {
            public array $added = [];
            protected function tmpStoreAvailable(): bool { return true; }
            protected function tmpStoreGet(string $key): ?array { return null; }
            protected function tmpStoreSet(string $key, array $data): void { $this->added = $data; }
        };
    }

    public function testOneTimeWindowIsPersisted(): void
    {
        $tester = new CommandTester(new ScheduleCommand($this->storage(), new OverlapDetector()));
        $tester->execute([
            '--from'     => '2026-06-03T02:00:00Z',
            '--to'       => '2026-06-03T04:00:00Z',
            '--reason'   => 'DB migration',
            '--timezone' => 'UTC',
        ]);

        $tester->assertCommandIsSuccessful();
        $out = $tester->getDisplay();
        self::assertStringContainsString('Scheduled', $out);
    }

    public function testDryRunDoesNotPersist(): void
    {
        $storage = $this->storage();
        $tester  = new CommandTester(new ScheduleCommand($storage, new OverlapDetector()));
        $tester->execute([
            '--from'     => '2026-06-03T02:00:00Z',
            '--to'       => '2026-06-03T04:00:00Z',
            '--dry-run'  => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $storage->added);
    }

    public function testMissingFromToAndCronDurationFails(): void
    {
        $tester = new CommandTester(new ScheduleCommand($this->storage(), new OverlapDetector()));
        $result = $tester->execute([]);

        self::assertSame(1, $result);
    }

    public function testCronWindowIsPersisted(): void
    {
        $tester = new CommandTester(new ScheduleCommand($this->storage(), new OverlapDetector()));
        $tester->execute([
            '--cron'     => '0 2 * * *',
            '--duration' => '60',
            '--timezone' => 'UTC',
        ]);

        $tester->assertCommandIsSuccessful();
    }

    public function testAnnounceBeforeIsPersistedToWindow(): void
    {
        $storage = $this->storage();
        $tester  = new CommandTester(new ScheduleCommand($storage, new OverlapDetector()));
        $tester->execute([
            '--from'            => '2026-06-03T02:00:00Z',
            '--to'              => '2026-06-03T04:00:00Z',
            '--announce-before' => '30',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(30, $storage->added[0]['announce_before_minutes']);
    }

    public function testScopeStoredOnScheduleWindow(): void
    {
        $storage = $this->storage();
        $tester  = new CommandTester(new ScheduleCommand($storage, new OverlapDetector()));
        $tester->execute([
            '--from'        => '2026-06-03T02:00:00Z',
            '--to'          => '2026-06-03T04:00:00Z',
            '--path-prefix' => ['/shop'],
            '--site-id'     => ['2'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertIsArray($storage->added[0]['scope']);
        self::assertSame(['/shop'], $storage->added[0]['scope']['path_prefixes']);
        self::assertSame([2], $storage->added[0]['scope']['site_ids']);
    }

    public function testOmittedScopeStoresNull(): void
    {
        $storage = $this->storage();
        $tester  = new CommandTester(new ScheduleCommand($storage, new OverlapDetector()));
        $tester->execute([
            '--from' => '2026-06-03T02:00:00Z',
            '--to'   => '2026-06-03T04:00:00Z',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($storage->added[0]['scope']);
    }

    public function testOverlapNoteShownWhenScopeProvided(): void
    {
        $storage = $this->storage();
        $tester  = new CommandTester(new ScheduleCommand($storage, new OverlapDetector()));
        $tester->execute([
            '--from'        => '2026-06-03T02:00:00Z',
            '--to'          => '2026-06-03T04:00:00Z',
            '--path-prefix' => ['/shop'],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('overlap check does not consider scope', $tester->getDisplay());
    }
}
