<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces;

interface QueuedWindowStorageInterface
{
    /** @return string[] Window IDs in FIFO order. */
    public function all(): array;

    public function enqueue(string $windowId): void;

    public function dequeueEarliest(): ?string;

    public function remove(string $windowId): void;

    public function isEmpty(): bool;

    public function clear(): void;
}
