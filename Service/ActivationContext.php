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
        // Preserve scheduler fields so the schedule enforcement task can still
        // auto-deactivate even when maintenance is re-asserted via CLI.
        $existing = $this->storage->load();
        $this->storage->save(
            $reason,
            $retryAfter,
            $existing['activated_by_schedule_window_id'] ?? null,
            $existing['expected_end_at'] ?? null,
            false,
            $existing['activated_by_history_record_id'] ?? null,
        );
    }

    public function clear(): void
    {
        $this->storage->clear();
    }
}
