<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;

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

    public function getActivatedByScheduleWindowId(): ?string
    {
        return $this->storage->load()['activated_by_schedule_window_id'] ?? null;
    }

    public function getExpectedEndAt(): ?\DateTimeImmutable
    {
        $raw = $this->storage->load()['expected_end_at'] ?? null;
        if ($raw === null) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $raw);
        return $dt !== false ? $dt : null;
    }

    public function isActivatedByHealthCheckFailure(): bool
    {
        return $this->storage->load()['activated_by_health_check_failure'];
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        $raw = $this->storage->load()['expires_at'] ?? null;
        if ($raw === null) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $raw);
        return $dt !== false ? $dt : null;
    }

    public function getOriginalTtlMinutes(): ?int
    {
        return $this->storage->load()['original_ttl_minutes'] ?? null;
    }

    public function getWarningEmittedAt(): ?\DateTimeImmutable
    {
        $raw = $this->storage->load()['warning_emitted_at'] ?? null;
        if ($raw === null) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $raw);
        return $dt !== false ? $dt : null;
    }

    public function set(
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
        $this->storage->save(
            $reason,
            $retryAfter,
            $activatedByScheduleWindowId,
            $expectedEndAt,
            $activatedByHealthCheckFailure,
            $activatedByHistoryRecordId,
            $expiresAt,
            $originalTtlMinutes,
            $warningEmittedAt,
        );
    }

    /**
     * Merge-update only the three TTL fields without touching reason, retry_after, or other fields.
     */
    public function updateExpiry(
        ?\DateTimeImmutable $expiresAt,
        ?int $originalTtlMinutes,
        ?\DateTimeImmutable $warningEmittedAt,
    ): void {
        $this->storage->updateExpiry(
            $expiresAt?->format(\DateTimeInterface::ATOM),
            $originalTtlMinutes,
            $warningEmittedAt?->format(\DateTimeInterface::ATOM),
        );
    }

    public function getScope(): ?MaintenanceScope
    {
        $raw = $this->storage->load()['scope'] ?? null;
        if (!\is_array($raw)) {
            return null;
        }
        return new MaintenanceScope(
            $raw['path_prefixes'],
            $raw['site_ids'],
        );
    }

    public function setScope(?MaintenanceScope $scope): void
    {
        $raw = $scope !== null
            ? ['path_prefixes' => $scope->pathPrefixes, 'site_ids' => $scope->siteIds]
            : null;
        $this->storage->saveScope($raw);
    }

    public function clear(): void
    {
        $this->storage->clear();
    }
}
