<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Repository\Fixtures;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ScheduleHistoryRepositoryInterface;

final class InMemoryScheduleHistoryRepository implements ScheduleHistoryRepositoryInterface
{
    private int $nextId = 1;
    /** @var array<int, array{window_id: string, start: \DateTimeImmutable}> */
    private array $records = [];
    /** @var array<int, \DateTimeImmutable> */
    private array $updated = [];

    #[\Override] public function insertStart(
        string $scheduleWindowId,
        \DateTimeImmutable $startedAt,
        string $type,
        ?string $reason,
        ?int $configuredDurationMinutes,
        ?array $scopePathPrefixes = null,
        ?array $scopeSiteIds = null,
    ): int {
        $id = $this->nextId++;
        $this->records[$id] = ['window_id' => $scheduleWindowId, 'start' => $startedAt];
        return $id;
    }

    #[\Override] public function updateEnd(int $historyId, \DateTimeImmutable $endedAt): void
    {
        $this->updated[$historyId] = $endedAt;
    }

    #[\Override] public function findPaginated(int $page, int $pageSize, ?string $scheduleWindowId = null, ?\DateTimeImmutable $startedAfter = null, ?\DateTimeImmutable $startedBefore = null): array
    {
        return [];
    }

    #[\Override] public function count(?string $scheduleWindowId = null): int { return 0; }

    public function insertCount(): int { return \count($this->records); }
    public function lastInsertedWindowId(): ?string
    {
        if ($this->records === []) {
            return null;
        }
        return \end($this->records)['window_id'];
    }
    public function seedRecord(int $id, string $windowId): void
    {
        $this->records[$id] = ['window_id' => $windowId, 'start' => new \DateTimeImmutable()];
    }
    public function wasEndUpdated(int $id): bool { return isset($this->updated[$id]); }
}
