<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

final class PreAnnounceData
{
    public function __construct(
        public readonly \DateTimeImmutable $at,
        public readonly string $timezone,
        public readonly ?string $reason,
        public readonly ?int $announceBeforeMinutes,
    ) {}
}
