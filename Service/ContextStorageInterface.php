<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

interface ContextStorageInterface
{
    /**
     * @return array{reason: ?string, retry_after: ?int}
     */
    public function load(): array;

    public function save(?string $reason, ?int $retryAfter): void;

    public function clear(): void;
}
