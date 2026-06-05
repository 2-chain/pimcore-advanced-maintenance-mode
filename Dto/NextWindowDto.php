<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto;

final readonly class NextWindowDto
{
    public function __construct(public ?string $reason, public ?string $startsAt) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
