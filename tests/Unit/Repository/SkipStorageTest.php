<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\SkipStorage;
use DateTimeImmutable;
use Override;

final class SkipStorageTest extends TestCase
{
    private function makeStorage(): SkipStorage
    {
        return new class extends SkipStorage {
            private array $store = [];

            #[Override]
            protected function loadMap(): array
            {
                return $this->store;
            }

            #[Override]
            protected function saveMap(array $map): void
            {
                $this->store = $map;
            }
        };
    }

    public function testNewWindowIsNotSkipped(): void
    {
        $s = $this->makeStorage();
        $now = new DateTimeImmutable('2026-06-02T10:00:00Z');
        self::assertFalse($s->shouldSkip('win-1', $now));
    }

    public function testSkippedWindowIsSkippedBeforeExpiry(): void
    {
        $s = $this->makeStorage();
        $skipUntil = new DateTimeImmutable('2026-06-02T12:00:00Z');
        $s->skip('win-1', $skipUntil);
        $now = new DateTimeImmutable('2026-06-02T11:00:00Z');
        self::assertTrue($s->shouldSkip('win-1', $now));
    }

    public function testSkipExpires(): void
    {
        $s = $this->makeStorage();
        $skipUntil = new DateTimeImmutable('2026-06-02T10:00:00Z');
        $s->skip('win-1', $skipUntil);
        $now = new DateTimeImmutable('2026-06-02T10:01:00Z');
        self::assertFalse($s->shouldSkip('win-1', $now));
    }

    public function testPruneExpiredRemovesStaleEntries(): void
    {
        $s = $this->makeStorage();
        $s->skip('win-old', new DateTimeImmutable('2026-06-02T09:00:00Z'));
        $s->skip('win-new', new DateTimeImmutable('2026-06-02T12:00:00Z'));

        $now = new DateTimeImmutable('2026-06-02T10:00:00Z');
        $s->pruneExpired($now);

        self::assertFalse($s->shouldSkip('win-old', $now));
        self::assertTrue($s->shouldSkip('win-new', $now));
    }
}
