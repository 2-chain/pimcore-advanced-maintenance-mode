<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model;

use DateTimeImmutable;
use DateTimeInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;

final class ActivationContext
{
    public function __construct(private readonly ContextStorageInterface $storage) {}

    public function getReason(): ?string
    {
        return $this->payload()['reason'];
    }

    public function getRetryAfter(): ?int
    {
        return $this->payload()['retry_after'];
    }

    public function getActivatedByScheduleWindowId(): ?string
    {
        return $this->payload()['activated_by_schedule_window_id'];
    }

    public function getExpectedEndAt(): ?DateTimeImmutable
    {
        $raw = $this->payload()['expected_end_at'];
        if ($raw === null) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
        return $dt !== false ? $dt : null;
    }

    public function isActivatedByHealthCheckFailure(): bool
    {
        return $this->payload()['activated_by_health_check_failure'];
    }

    public function getActivatedByHistoryRecordId(): ?int
    {
        return $this->payload()['activated_by_history_record_id'];
    }

    public function set(
        ?string $reason,
        ?int $retryAfter,
        ?string $activatedByScheduleWindowId = null,
        ?DateTimeImmutable $expectedEndAt = null,
        bool $activatedByHealthCheckFailure = false,
        ?int $activatedByHistoryRecordId = null,
    ): void {
        $this->storage->save(
            $reason,
            $retryAfter,
            $activatedByScheduleWindowId,
            $expectedEndAt?->format(DateTimeInterface::ATOM),
            $activatedByHealthCheckFailure,
            $activatedByHistoryRecordId,
        );
    }

    public function setActivatedByHistoryRecordId(int $id): void
    {
        $data = $this->payload();
        $this->storage->save(
            reason: $data['reason'],
            retryAfter: $data['retry_after'],
            activatedByScheduleWindowId: $data['activated_by_schedule_window_id'],
            expectedEndAt: $data['expected_end_at'],
            activatedByHealthCheckFailure: $data['activated_by_health_check_failure'],
            activatedByHistoryRecordId: $id,
        );
    }

    public function clear(): void
    {
        $this->storage->clear();
    }

    private function payload(): array
    {
        return $this->storage->load();
    }
}
