<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\ContextStorageInterface;

final class PimcoreTmpStoreContextStorageScopeTest extends TestCase
{
    private function fakeStorage(?array $scopeRaw): ContextStorageInterface
    {
        return new class ($scopeRaw) implements ContextStorageInterface {
            public function __construct(private readonly ?array $scopeRaw) {}

            public function load(): array
            {
                return [
                    'reason'                              => null,
                    'retry_after'                         => null,
                    'activated_by_schedule_window_id'     => null,
                    'expected_end_at'                     => null,
                    'activated_by_health_check_failure'   => false,
                    'activated_by_history_record_id'      => null,
                    'expires_at'                          => null,
                    'original_ttl_minutes'                => null,
                    'warning_emitted_at'                  => null,
                    'scope'                               => $this->scopeRaw,
                ];
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
            ): void {}

            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}

            public function saveScope(?array $scopeRaw): void {}

            public function clear(): void {}
        };
    }

    public function testLegacyPayloadMissingScopeKeyDeserializesToNull(): void
    {
        $storage = new class implements ContextStorageInterface {
            public function load(): array
            {
                // Old payload with no 'scope' key
                return ['reason' => null, 'retry_after' => null];
            }
            public function save(?string $reason, ?int $retryAfter, ?string $activatedByScheduleWindowId = null, ?string $expectedEndAt = null, bool $activatedByHealthCheckFailure = false, ?int $activatedByHistoryRecordId = null, ?string $expiresAt = null, ?int $originalTtlMinutes = null, ?string $warningEmittedAt = null): void {}
            public function updateExpiry(?string $expiresAt, ?int $originalTtlMinutes, ?string $warningEmittedAt): void {}
            public function saveScope(?array $scopeRaw): void {}
            public function clear(): void {}
        };

        $ctx = new ActivationContext($storage);
        self::assertNull($ctx->getScope());
    }

    public function testNullScopeKeyDeserializesToNull(): void
    {
        $ctx = new ActivationContext($this->fakeStorage(null));
        self::assertNull($ctx->getScope());
    }

    public function testScopeArrayDeserializesToMaintenanceScope(): void
    {
        $ctx = new ActivationContext(
            $this->fakeStorage(['path_prefixes' => ['/shop'], 'site_ids' => [2, 3]])
        );

        $scope = $ctx->getScope();
        self::assertNotNull($scope);
        self::assertSame(['/shop'], $scope->pathPrefixes);
        self::assertSame([2, 3], $scope->siteIds);
    }
}
