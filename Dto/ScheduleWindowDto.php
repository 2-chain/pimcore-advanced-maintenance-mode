<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto;

final readonly class ScheduleWindowDto
{
    public function __construct(
        public string $id,
        public string $type,
        public string $timezone,
        public ?string $reason,
        public ?string $from,
        public ?string $to,
        public ?string $cronExpression,
        public ?int $durationMinutes,
        public ?int $announceBeforeMinutes,
        public int $createdByUserId,
        public string $createdByUsername,
        public bool $activeNow,
        public bool $queued,
        public array $overlappingWith,
        public array $nextFires,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
