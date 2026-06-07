<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class PendingHealthCheckStorage
{
    private const KEY = 'advanced_maintenance_pending_health_check';

    /**
     * @param 'schedule_ended'|'ttl_expired'|'end_now' $triggeredBy
     */
    public function write(string $triggeredBy): void
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        \Pimcore\Model\Tool\TmpStore::set(self::KEY, [
            'retry_count'    => 0,
            'triggered_by'   => $triggeredBy,
            'deactivated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @return array{retry_count: int, triggered_by: string, deactivated_at: string}|null
     */
    public function read(): ?array
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return null;
        }

        $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        if ($entry === null) {
            return null;
        }

        $data = $entry->getData();
        if (!\is_array($data)) {
            return null;
        }

        if (!isset($data['retry_count'], $data['triggered_by'], $data['deactivated_at'])) {
            return null;
        }

        return [
            'retry_count'    => (int) $data['retry_count'],
            'triggered_by'   => (string) $data['triggered_by'],
            'deactivated_at' => (string) $data['deactivated_at'],
        ];
    }

    public function incrementRetryCount(): void
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        if ($entry === null) {
            return;
        }

        $data = $entry->getData();
        if (!\is_array($data)) {
            return;
        }

        $data['retry_count'] = ((int) ($data['retry_count'] ?? 0)) + 1;
        \Pimcore\Model\Tool\TmpStore::set(self::KEY, $data);
    }

    public function clear(): void
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        \Pimcore\Model\Tool\TmpStore::delete(self::KEY);
    }
}
