<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface;

final class QueuedWindowStorage implements QueuedWindowStorageInterface
{
    private const KEY = 'advanced_maintenance_queued_windows';

    /** @return string[] window IDs in FIFO order */
    public function all(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $entry = \Pimcore\Model\Tool\TmpStore::get(self::KEY);
        if ($entry === null) {
            return [];
        }
        $data = $entry->getData();
        return is_array($data) ? $data : [];
    }

    public function enqueue(string $windowId): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $ids = $this->all();
        if (!in_array($windowId, $ids, true)) {
            $ids[] = $windowId;
            \Pimcore\Model\Tool\TmpStore::set(self::KEY, $ids);
        }
    }

    public function dequeueEarliest(): ?string
    {
        $ids = $this->all();
        if ($ids === []) {
            return null;
        }
        $id = array_shift($ids);
        \Pimcore\Model\Tool\TmpStore::set(self::KEY, $ids);
        return $id;
    }

    public function remove(string $windowId): void
    {
        $ids = $this->all();
        $filtered = array_values(array_filter($ids, static fn(string $id) => $id !== $windowId));
        if (count($filtered) === count($ids)) {
            return;
        }
        \Pimcore\Model\Tool\TmpStore::set(self::KEY, $filtered);
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    public function clear(): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        \Pimcore\Model\Tool\TmpStore::delete(self::KEY);
    }

    private function isAvailable(): bool
    {
        return class_exists(\Pimcore\Model\Tool\TmpStore::class);
    }
}
