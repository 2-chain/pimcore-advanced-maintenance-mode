<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PendingHealthCheckStorage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Override;

/**
 * Tests PendingHealthCheckStorage using an in-memory fake that bypasses TmpStore.
 * The real class guards with class_exists(\Pimcore\Model\Tool\TmpStore::class),
 * so in unit tests (no Pimcore) write/read/clear are all no-ops unless we use the fake.
 */
final class PendingHealthCheckStorageTest extends TestCase
{
    public function testWriteAndReadRoundTrip(): void
    {
        $storage = new FakePendingHealthCheckStorage();
        $storage->write('schedule_ended');

        $data = $storage->read();

        self::assertNotNull($data);
        self::assertSame(0, $data['retry_count']);
        self::assertSame('schedule_ended', $data['triggered_by']);
        self::assertNotEmpty($data['deactivated_at']);
    }

    public function testReadReturnsNullWhenEmpty(): void
    {
        $storage = new FakePendingHealthCheckStorage();

        self::assertNull($storage->read());
    }

    public function testIncrementRetryCountIncrementsExistingEntry(): void
    {
        $storage = new FakePendingHealthCheckStorage();
        $storage->write('ttl_expired');
        $storage->incrementRetryCount();

        $data = $storage->read();

        self::assertSame(1, $data['retry_count']);
    }

    public function testIncrementRetryCountTwice(): void
    {
        $storage = new FakePendingHealthCheckStorage();
        $storage->write('end_now');
        $storage->incrementRetryCount();
        $storage->incrementRetryCount();

        $data = $storage->read();

        self::assertSame(2, $data['retry_count']);
    }

    public function testClearMakesReadReturnNull(): void
    {
        $storage = new FakePendingHealthCheckStorage();
        $storage->write('schedule_ended');
        $storage->clear();

        self::assertNull($storage->read());
    }

    public function testIncrementRetryCountOnEmptyStorageIsNoOp(): void
    {
        $storage = new FakePendingHealthCheckStorage();
        $storage->incrementRetryCount(); // must not throw

        self::assertNull($storage->read());
    }
}

/**
 * In-memory subclass that bypasses TmpStore for unit testing.
 */
final class FakePendingHealthCheckStorage extends PendingHealthCheckStorage
{
    /** @var array{retry_count: int, triggered_by: string, deactivated_at: string}|null */
    private ?array $data = null;

    #[Override]
    public function write(string $triggeredBy): void
    {
        $this->data = [
            'retry_count'    => 0,
            'triggered_by'   => $triggeredBy,
            'deactivated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public function read(): ?array
    {
        return $this->data;
    }

    #[Override]
    public function incrementRetryCount(): void
    {
        if ($this->data === null) {
            return;
        }

        $this->data['retry_count']++;
    }

    #[Override]
    public function clear(): void
    {
        $this->data = null;
    }
}
