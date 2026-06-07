<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository;

use Override;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;

final class PimcoreTmpStoreContextStorage implements ContextStorageInterface
{
    private const KEY = 'advanced_maintenance_mode_context';

    /**
     * @return array{reason: ?string, retry_after: ?int, activated_by_schedule_window_id: ?string, expected_end_at: ?string, activated_by_health_check_failure: bool, activated_by_history_record_id: ?int, expires_at: ?string, original_ttl_minutes: ?int, warning_emitted_at: ?string, scope?: ?array{path_prefixes: string[], site_ids: int[]}}
     */
    #[Override]
    public function load(): array
    {
        if (!class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return $this->empty();
        }

        $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        if ($entry === null) {
            return $this->empty();
        }

        $data = $entry->getData();
        if (!is_array($data)) {
            return $this->empty();
        }

        return [
            'reason'                            => isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null,
            'retry_after'                       => isset($data['retry_after']) && is_int($data['retry_after']) ? $data['retry_after'] : null,
            'activated_by_schedule_window_id'   => isset($data['activated_by_schedule_window_id']) && is_string($data['activated_by_schedule_window_id']) ? $data['activated_by_schedule_window_id'] : null,
            'expected_end_at'                   => isset($data['expected_end_at']) && is_string($data['expected_end_at']) ? $data['expected_end_at'] : null,
            'activated_by_health_check_failure' => isset($data['activated_by_health_check_failure']) && is_bool($data['activated_by_health_check_failure']) ? $data['activated_by_health_check_failure'] : false,
            'activated_by_history_record_id'    => isset($data['activated_by_history_record_id']) && is_int($data['activated_by_history_record_id']) ? $data['activated_by_history_record_id'] : null,
            'expires_at'                        => isset($data['expires_at']) && is_string($data['expires_at']) ? $data['expires_at'] : null,
            'original_ttl_minutes'              => isset($data['original_ttl_minutes']) && is_int($data['original_ttl_minutes']) ? $data['original_ttl_minutes'] : null,
            'warning_emitted_at'                => isset($data['warning_emitted_at']) && is_string($data['warning_emitted_at']) ? $data['warning_emitted_at'] : null,
            'scope'                             => $this->parseScopeRaw($data['scope'] ?? null),
        ];
    }

    #[Override]
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
        if (!class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        \Pimcore\Model\Tool\TmpStore::set(self::KEY, [
            'reason'                            => $reason,
            'retry_after'                       => $retryAfter,
            'activated_by_schedule_window_id'   => $activatedByScheduleWindowId,
            'expected_end_at'                   => $expectedEndAt,
            'activated_by_health_check_failure' => $activatedByHealthCheckFailure,
            'activated_by_history_record_id'    => $activatedByHistoryRecordId,
            'expires_at'                        => $expiresAt,
            'original_ttl_minutes'              => $originalTtlMinutes,
            'warning_emitted_at'                => $warningEmittedAt,
            'scope'                             => null,  // scope is always set separately via saveScope() right after
        ]);
    }

    #[Override]
    public function updateExpiry(
        ?string $expiresAt,
        ?int $originalTtlMinutes,
        ?string $warningEmittedAt,
    ): void {
        if (!class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        $current = $this->load();
        $current['expires_at']           = $expiresAt;
        $current['original_ttl_minutes'] = $originalTtlMinutes;
        $current['warning_emitted_at']   = $warningEmittedAt;

        \Pimcore\Model\Tool\TmpStore::set(self::KEY, $current);
    }

    #[Override]
    public function clear(): void
    {
        if (!class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        \Pimcore\Model\Tool\TmpStore::delete(self::KEY);
    }

    #[Override]
    public function saveScope(?array $scopeRaw): void
    {
        if (!class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        $data  = ($entry !== null && \is_array($entry->getData())) ? $entry->getData() : $this->empty();
        $data['scope'] = $scopeRaw;
        \Pimcore\Model\Tool\TmpStore::set(self::KEY, $data);
    }

    /** @return ?array{path_prefixes: string[], site_ids: int[]} */
    private function parseScopeRaw(mixed $raw): ?array
    {
        if (!\is_array($raw)
            || !isset($raw['path_prefixes'], $raw['site_ids'])
            || !\is_array($raw['path_prefixes'])
            || !\is_array($raw['site_ids'])
        ) {
            return null;
        }
        /** @var string[] $pathPrefixes */
        $pathPrefixes = \array_values(\array_filter($raw['path_prefixes'], static fn(mixed $v): bool => \is_string($v)));
        /** @var int[] $siteIds */
        $siteIds = \array_values(\array_filter($raw['site_ids'], static fn(mixed $v): bool => \is_int($v)));
        return ['path_prefixes' => $pathPrefixes, 'site_ids' => $siteIds];
    }

    /** @return array{reason: null, retry_after: null, activated_by_schedule_window_id: null, expected_end_at: null, activated_by_health_check_failure: false, activated_by_history_record_id: null, expires_at: null, original_ttl_minutes: null, warning_emitted_at: null, scope: null} */
    private function empty(): array
    {
        return [
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
