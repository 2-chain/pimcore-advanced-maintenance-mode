<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto;

final readonly class ActivationContextDto
{
    public function __construct(
        public ?string $activatedByScheduleWindowId,
        public ?string $reason,
        public ?int $retryAfter,
        public ?string $expectedEndAt,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
