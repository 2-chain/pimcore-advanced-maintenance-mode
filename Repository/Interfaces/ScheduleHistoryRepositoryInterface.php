<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces;

use DateTimeImmutable;

interface ScheduleHistoryRepositoryInterface
{
    public function insertStart(
        string $scheduleWindowId,
        DateTimeImmutable $startedAt,
        string $type,
        ?string $reason,
        ?int $configuredDurationMinutes,
        ?array $scopePathPrefixes = null,
        ?array $scopeSiteIds = null,
    ): int;

    public function updateEnd(int $historyId, DateTimeImmutable $endedAt, ?string $endedReason = null): void;

    public function findInProgressIdByWindowId(string $windowId): ?int;

    /** @return \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Entity\ScheduleHistoryRecord[] */
    public function findPaginated(
        int $page,
        int $pageSize,
        ?string $scheduleWindowId = null,
        ?DateTimeImmutable $startedAfter = null,
        ?DateTimeImmutable $startedBefore = null,
    ): array;

    public function count(?string $scheduleWindowId = null): int;

    public function isInProgress(int $id): bool;
}
