<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use DateTimeImmutable;

final class PreAnnounceData
{
    public function __construct(
        public readonly DateTimeImmutable $at,
        public readonly string             $timezone,
        public readonly ?string            $reason,
        public readonly ?int               $announceBeforeMinutes,
        public readonly ?MaintenanceScope  $scope = null,
    ) {}
}
