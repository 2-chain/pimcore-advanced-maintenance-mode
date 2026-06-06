<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\ContextStorageInterface;

final class InMemoryContextStorage implements ContextStorageInterface
{
    private array $state = [
        'reason'                            => null,
        'retry_after'                       => null,
        'activated_by_schedule_window_id'   => null,
        'expected_end_at'                   => null,
        'activated_by_health_check_failure' => false,
        'activated_by_history_record_id'    => null,
        'expires_at'                        => null,
        'original_ttl_minutes'              => null,
        'warning_emitted_at'                => null,
        'scope'                             => null,
    ];

    public function load(): array
    {
        return $this->state;
    }

    public function save(
        ?string $reason,
        ?int $retryAfter,
        ?string $activatedByScheduleWindowId = null,
        ?string $expectedEndAt = null,
        bool $activatedByHealthCheckFailure = false,
        ?int $activatedByHistoryRecordId = null,
        ?string $expiresAt = null,
        ?int $originalTtlMinutes = null,
        ?string $warningEmittedAt = null,
    ): void {
        $this->state = [
            'reason'                            => $reason,
            'retry_after'                       => $retryAfter,
            'activated_by_schedule_window_id'   => $activatedByScheduleWindowId,
            'expected_end_at'                   => $expectedEndAt,
            'activated_by_health_check_failure' => $activatedByHealthCheckFailure,
            'activated_by_history_record_id'    => $activatedByHistoryRecordId,
            'expires_at'                        => $expiresAt,
            'original_ttl_minutes'              => $originalTtlMinutes,
            'warning_emitted_at'                => $warningEmittedAt,
        ];
    }

    public function updateExpiry(
        ?string $expiresAt,
        ?int $originalTtlMinutes,
        ?string $warningEmittedAt,
    ): void {
        $this->state['expires_at']           = $expiresAt;
        $this->state['original_ttl_minutes'] = $originalTtlMinutes;
        $this->state['warning_emitted_at']   = $warningEmittedAt;
    }

    public function saveScope(?array $scopeRaw): void
    {
        $this->state['scope'] = $scopeRaw;
    }

    public function clear(): void
    {
        $this->state = [
            'reason'                            => null,
            'retry_after'                       => null,
            'activated_by_schedule_window_id'   => null,
            'expected_end_at'                   => null,
            'activated_by_health_check_failure' => false,
            'activated_by_history_record_id'    => null,
            'expires_at'                        => null,
            'original_ttl_minutes'              => null,
            'warning_emitted_at'                => null,
            'scope'                             => null,
        ];
    }
}
