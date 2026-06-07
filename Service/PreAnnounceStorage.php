<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

class PreAnnounceStorage
{
    private const KEY = 'advanced_maintenance_pre_announce';

    public function save(PreAnnounceData $data): void
    {
        $this->tmpStoreSet([
            'at'                      => $data->at->format(DateTimeInterface::ATOM),
            'timezone'                => $data->timezone,
            'reason'                  => $data->reason,
            'announce_before_minutes' => $data->announceBeforeMinutes,
        ]);
    }

    public function load(): ?PreAnnounceData
    {
        $row = $this->tmpStoreGet();
        if ($row === null || !isset($row['at'])) {
            return null;
        }

        $atRaw = $row['at'];
        $at    = \is_string($atRaw) ? DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $atRaw) : false;
        if ($at === false) {
            return null;
        }

        return new PreAnnounceData(
            at: $at->setTimezone(new DateTimeZone('UTC')),
            timezone: isset($row['timezone']) && \is_string($row['timezone']) ? $row['timezone'] : 'UTC',
            reason: isset($row['reason']) && \is_string($row['reason']) ? $row['reason'] : null,
            announceBeforeMinutes: isset($row['announce_before_minutes']) && \is_int($row['announce_before_minutes'])
                ? $row['announce_before_minutes']
                : null,
        );
    }

    public function clear(): void
    {
        $this->tmpStoreClear();
    }

    /** @return array<string, mixed>|null */
    protected function tmpStoreGet(): ?array
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return null;
        }
        try {
            $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        } catch (Throwable) {
            return null;
        }
        if ($entry === null) {
            return null;
        }
        $data = $entry->getData();
        return \is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    protected function tmpStoreSet(array $data): void
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }
        try {
            \Pimcore\Model\Tool\TmpStore::set(self::KEY, $data);
        } catch (Throwable) {
            // Pimcore not bootstrapped — no-op
        }
    }

    protected function tmpStoreClear(): void
    {
        if (!\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }
        try {
            \Pimcore\Model\Tool\TmpStore::delete(self::KEY);
        } catch (Throwable) {
            // Pimcore not bootstrapped — no-op
        }
    }
}
