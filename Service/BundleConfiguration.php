<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

final readonly class BundleConfiguration
{
    public function __construct(
        public bool $bypassAuthenticatedAdmins,
        public ?int $defaultRetryAfter,
        public bool $publicStatusEnabled,
        public ?string $publicStatusToken,
    ) {}
}
