<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Functional\Fixtures;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;

final class InMemoryContextStorage implements ContextStorageInterface
{
    /** @var array{reason: ?string, retry_after: ?int} */
    private array $state = ['reason' => null, 'retry_after' => null];

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
    ): void {
        $this->state = ['reason' => $reason, 'retry_after' => $retryAfter];
    }

    public function clear(): void
    {
        $this->state = ['reason' => null, 'retry_after' => null];
    }
}
