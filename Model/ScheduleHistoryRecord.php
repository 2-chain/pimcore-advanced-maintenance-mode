<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model;

final readonly class ScheduleHistoryRecord
{
    public function __construct(
        public int $id,
        public string $scheduleWindowId,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        public ?int $durationMinutes,
        public ?int $configuredDurationMinutes,
        public ?string $type,
        public ?string $reason,
        public ?array $scopePathPrefixes,
        public ?array $scopeSiteIds,
    ) {}

    public function isInProgress(): bool
    {
        return $this->endedAt === null;
    }
}
