<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;
use DateTimeImmutable;

final class PreAnnounceStorageTest extends TestCase
{
    private function makeStorage(): PreAnnounceStorage
    {
        // Stub TmpStore so no Pimcore bootstrap needed
        return new class extends PreAnnounceStorage {
            private ?array $stored = null;

            protected function tmpStoreGet(): ?array
            {
                return $this->stored;
            }
            protected function tmpStoreSet(array $data): void
            {
                $this->stored = $data;
            }
            protected function tmpStoreClear(): void
            {
                $this->stored = null;
            }
        };
    }

    public function testRoundtrip(): void
    {
        $storage = $this->makeStorage();
        $data = new PreAnnounceData(
            at: new DateTimeImmutable('2026-07-01T10:00:00Z'),
            timezone: 'Europe/Berlin',
            reason: 'DB migration',
            announceBeforeMinutes: 30,
        );

        $storage->save($data);
        $loaded = $storage->load();

        self::assertNotNull($loaded);
        self::assertSame($data->at->getTimestamp(), $loaded->at->getTimestamp());
        self::assertSame('Europe/Berlin', $loaded->timezone);
        self::assertSame('DB migration', $loaded->reason);
        self::assertSame(30, $loaded->announceBeforeMinutes);
    }

    public function testLoadReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->makeStorage()->load());
    }

    public function testClearRemovesEntry(): void
    {
        $storage = $this->makeStorage();
        $storage->save(new PreAnnounceData(
            at: new DateTimeImmutable('2026-07-01T10:00:00Z'),
            timezone: 'UTC',
            reason: null,
            announceBeforeMinutes: null,
        ));
        $storage->clear();
        self::assertNull($storage->load());
    }

    public function testNullableFieldsRoundtrip(): void
    {
        $storage = $this->makeStorage();
        $storage->save(new PreAnnounceData(
            at: new DateTimeImmutable('2026-07-01T10:00:00Z'),
            timezone: 'UTC',
            reason: null,
            announceBeforeMinutes: null,
        ));
        $loaded = $storage->load();
        self::assertNull($loaded?->reason);
        self::assertNull($loaded?->announceBeforeMinutes);
    }
}
