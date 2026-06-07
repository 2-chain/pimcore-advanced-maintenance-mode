<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\ScheduleListCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;
use DateTimeImmutable;
use DateTimeZone;

final class ScheduleListCommandTest extends TestCase
{
    private function nullPreAnnounceStorage(): PreAnnounceStorage
    {
        return new class extends PreAnnounceStorage {
            public function load(): ?PreAnnounceData
            {
                return null;
            }
        };
    }

    public function testListsStoredWindows(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([
            new ScheduleWindow(
                'w1',
                'UTC',
                'deploy',
                new DateTimeImmutable('2026-06-03T02:00:00Z'),
                new DateTimeImmutable('2026-06-03T04:00:00Z'),
                null,
                null
            ),
        ]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('w1', $tester->getDisplay());
        self::assertStringContainsString('deploy', $tester->getDisplay());
    }

    public function testEmptyStorageShowsMessage(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No scheduled', $tester->getDisplay());
    }

    public function testShowsAnnounceBeforeMinutesWhenSet(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([
            new ScheduleWindow(
                'w2',
                'UTC',
                null,
                new DateTimeImmutable('2026-06-03T02:00:00Z'),
                new DateTimeImmutable('2026-06-03T04:00:00Z'),
                null,
                null,
                announceBeforeMinutes: 30,
            ),
        ]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('30 min', $tester->getDisplay());
    }

    public function testShowsDashWhenAnnounceBeforeIsZero(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([
            new ScheduleWindow(
                'w3',
                'UTC',
                null,
                new DateTimeImmutable('2026-06-03T02:00:00Z'),
                new DateTimeImmutable('2026-06-03T04:00:00Z'),
                null,
                null,
                announceBeforeMinutes: 0,
            ),
        ]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $out = $tester->getDisplay();
        self::assertStringNotContainsString('0 min', $out);
        self::assertStringContainsString('Announce Before', $out);
    }

    public function testShowsCreatedByUsername(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([
            new ScheduleWindow(
                'w4',
                'UTC',
                null,
                new DateTimeImmutable('2026-06-03T02:00:00Z'),
                new DateTimeImmutable('2026-06-03T04:00:00Z'),
                null,
                null,
                announceBeforeMinutes: 0,
                createdByUserId: 1,
                createdByUsername: 'admin',
            ),
        ]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('admin', $tester->getDisplay());
    }

    public function testScopeColumnGlobal(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([
            new ScheduleWindow(
                'w5',
                'UTC',
                null,
                new DateTimeImmutable('2026-06-03T02:00:00Z'),
                new DateTimeImmutable('2026-06-03T04:00:00Z'),
                null,
                null,
            ),
        ]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('global', $tester->getDisplay());
    }

    public function testScopeColumnPath(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([
            new ScheduleWindow(
                'w6',
                'UTC',
                null,
                new DateTimeImmutable('2026-06-03T02:00:00Z'),
                new DateTimeImmutable('2026-06-03T04:00:00Z'),
                null,
                null,
                scope: new MaintenanceScope(['/shop'], []),
            ),
        ]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('/shop', $tester->getDisplay());
    }

    public function testScopeColumnSite(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([
            new ScheduleWindow(
                'w7',
                'UTC',
                null,
                new DateTimeImmutable('2026-06-03T02:00:00Z'),
                new DateTimeImmutable('2026-06-03T04:00:00Z'),
                null,
                null,
                scope: new MaintenanceScope([], [2, 3]),
            ),
        ]);

        $tester = new CommandTester(new ScheduleListCommand($storage, $this->nullPreAnnounceStorage()));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('site 2', $tester->getDisplay());
    }

    public function testShowsManualPreAnnouncement(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([]);

        $at = new DateTimeImmutable('+2 hours', new DateTimeZone('UTC'));
        $preAnnounceStorage = new class ($at) extends PreAnnounceStorage {
            public function __construct(private readonly DateTimeImmutable $at) {}
            public function load(): ?PreAnnounceData
            {
                return new PreAnnounceData($this->at, 'UTC', 'Deployment', null);
            }
        };

        $tester = new CommandTester(new ScheduleListCommand($storage, $preAnnounceStorage));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $out = $tester->getDisplay();
        self::assertStringContainsString('Manual pre-announcement', $out);
        self::assertStringContainsString('Deployment', $out);
    }
}
