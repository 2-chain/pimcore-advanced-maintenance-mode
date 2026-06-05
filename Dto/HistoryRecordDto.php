<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto;

final readonly class HistoryRecordDto
{
    public function __construct(
        public int $id,
        public string $scheduleWindowId,
        public string $startedAt,
        public ?string $endedAt,
        public ?int $durationMinutes,
        public ?int $configuredDurationMinutes,
        public ?string $type,
        public ?string $reason,
        public bool $inProgress,
        public ?bool $overrun,
        public ?string $endedReason,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
