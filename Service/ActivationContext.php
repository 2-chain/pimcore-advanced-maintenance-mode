<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

final class ActivationContext
{
    public function __construct(private readonly ContextStorageInterface $storage) {}

    public function getReason(): ?string
    {
        return $this->storage->load()['reason'];
    }

    public function getRetryAfter(): ?int
    {
        return $this->storage->load()['retry_after'];
    }

    public function set(?string $reason, ?int $retryAfter): void
    {
        $this->storage->save($reason, $retryAfter);
    }

    public function clear(): void
    {
        $this->storage->clear();
    }
}
