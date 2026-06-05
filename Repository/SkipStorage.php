<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository;

use DateTimeImmutable;
use DateTimeInterface;

class SkipStorage
{
    private const KEY = 'advanced_maintenance_skip_storage';

    public function skip(string $windowId, DateTimeImmutable $skipUntil): void
    {
        $map = $this->loadMap();
        $map[$windowId] = $skipUntil->format(DateTimeInterface::ATOM);
        $this->saveMap($map);
    }

    public function shouldSkip(string $windowId, DateTimeImmutable $now): bool
    {
        $map = $this->loadMap();
        if (!isset($map[$windowId])) {
            return false;
        }

        $until = new DateTimeImmutable($map[$windowId]);
        return $now < $until;
    }

    public function pruneExpired(DateTimeImmutable $now): void
    {
        $map = $this->loadMap();
        $pruned = array_filter(
            $map,
            static fn(string $until) => $now < new DateTimeImmutable($until),
        );
        $this->saveMap($pruned);
    }

    protected function loadMap(): array
    {
        if (!class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return [];
        }

        $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        if ($entry === null) {
            return [];
        }

        $data = $entry->getData();
        return is_array($data) ? $data : [];
    }

    protected function saveMap(array $map): void
    {
        if (!class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            return;
        }

        \Pimcore\Model\Tool\TmpStore::set(self::KEY, $map);
    }
}
