<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces;

interface ContextStorageInterface
{
    /**
     * @return array{
     *   reason: ?string,
     *   retry_after: ?int,
     *   activated_by_schedule_window_id: ?string,
     *   expected_end_at: ?string,
     *   activated_by_health_check_failure: bool,
     *   activated_by_history_record_id: ?int,
     *   expires_at: ?string,
     *   original_ttl_minutes: ?int,
     *   warning_emitted_at: ?string,
     *   scope?: ?array{path_prefixes: string[], site_ids: int[]},
     * }
     */
    public function load(): array;

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
    ): void;

    /**
     * Merge-update only the three TTL fields, preserving all other fields.
     */
    public function updateExpiry(
        ?string $expiresAt,
        ?int $originalTtlMinutes,
        ?string $warningEmittedAt,
    ): void;

    public function clear(): void;

    /** @param ?array{path_prefixes: string[], site_ids: int[]} $scopeRaw */
    public function saveScope(?array $scopeRaw): void;
}
