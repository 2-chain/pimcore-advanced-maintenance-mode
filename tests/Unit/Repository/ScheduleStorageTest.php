<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;

final class ScheduleStorageTest extends TestCase
{
    private function storage(): ScheduleStorage
    {
        return new class extends ScheduleStorage {
            /** @var array<string, mixed> */
            private array $store = [];

            protected function tmpStoreGet(string $key): ?array { return $this->store[$key] ?? null; }
            protected function tmpStoreSet(string $key, array $data): void { $this->store[$key] = $data; }
            protected function tmpStoreAvailable(): bool { return true; }
        };
    }

    private function oneTimeWindow(string $id): ScheduleWindow
    {
        return new ScheduleWindow($id, 'UTC', 'test', new \DateTimeImmutable('2026-06-02T02:00:00Z'), new \DateTimeImmutable('2026-06-02T04:00:00Z'), null, null);
    }

    public function testAddAndFindAll(): void
    {
        $s = $this->storage();
        $w = $this->oneTimeWindow('win-1');

        $s->add($w);

        $all = $s->findAll();
        self::assertCount(1, $all);
        self::assertSame('win-1', $all[0]->id);
    }

    public function testFindById(): void
    {
        $s = $this->storage();
        $s->add($this->oneTimeWindow('win-1'));

        self::assertNotNull($s->findById('win-1'));
        self::assertNull($s->findById('missing'));
    }

    public function testRemoveDeletesWindow(): void
    {
        $s = $this->storage();
        $s->add($this->oneTimeWindow('win-1'));
        $s->add($this->oneTimeWindow('win-2'));

        $s->remove('win-1');

        self::assertCount(1, $s->findAll());
        self::assertNull($s->findById('win-1'));
    }

    public function testDuplicateIdIsRejected(): void
    {
        $s = $this->storage();
        $s->add($this->oneTimeWindow('win-1'));

        $this->expectException(\InvalidArgumentException::class);
        $s->add($this->oneTimeWindow('win-1'));
    }

    public function testCreatedByFieldsRoundTrip(): void
    {
        $storage = $this->storage();
        $window  = new ScheduleWindow('win-roundtrip', 'UTC', 'Deploy', null, null, '*/5 * * * *', 30, 10, 3, 'editor');

        $storage->add($window);
        $found = $storage->findById('win-roundtrip');

        self::assertNotNull($found);
        self::assertSame(3, $found->createdByUserId);
        self::assertSame('editor', $found->createdByUsername);
    }
}
