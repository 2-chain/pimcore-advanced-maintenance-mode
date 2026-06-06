<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Interfaces\ContextStorageInterface;

final class ActivationContextTest extends TestCase
{
    private function fakeStorage(array $overrides = []): ContextStorageInterface
    {
        return new class ($overrides) implements ContextStorageInterface {
            /** @var array */
            private array $state;

            public function __construct(array $overrides)
            {
                $this->state = array_merge([
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
                ], $overrides);
            }

            public function load(): array { return $this->state; }

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
                $this->state = [
                    'reason'                            => $reason,
                    'retry_after'                       => $retryAfter,
                    'activated_by_schedule_window_id'   => $activatedByScheduleWindowId,
                    'expected_end_at'                   => $expectedEndAt,
                    'activated_by_health_check_failure' => $activatedByHealthCheckFailure,
                    'activated_by_history_record_id'    => $activatedByHistoryRecordId,
                    'expires_at'                        => $expiresAt,
                    'original_ttl_minutes'              => $originalTtlMinutes,
                    'warning_emitted_at'                => $warningEmittedAt,
                    'scope'                             => $this->state['scope'] ?? null,
                ];
            }

            public function updateExpiry(
                ?string $expiresAt,
                ?int $originalTtlMinutes,
                ?string $warningEmittedAt,
            ): void {
                $this->state['expires_at']           = $expiresAt;
                $this->state['original_ttl_minutes'] = $originalTtlMinutes;
                $this->state['warning_emitted_at']   = $warningEmittedAt;
            }

            public function saveScope(?array $scopeRaw): void
            {
                $this->state['scope'] = $scopeRaw;
            }

            public function clear(): void
            {
                $this->state = [
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
        };
    }

    public function testDefaultsAreNull(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());

        self::assertNull($ctx->getReason());
        self::assertNull($ctx->getRetryAfter());
    }

    public function testSetReadsBack(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $ctx->set('DB migration', 600);

        self::assertSame('DB migration', $ctx->getReason());
        self::assertSame(600, $ctx->getRetryAfter());
    }

    public function testClearWipesState(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $ctx->set('x', 10);
        $ctx->clear();

        self::assertNull($ctx->getReason());
        self::assertNull($ctx->getRetryAfter());
    }

    public function testTtlFieldsDefaultToNull(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());

        self::assertNull($ctx->getExpiresAt());
        self::assertNull($ctx->getOriginalTtlMinutes());
        self::assertNull($ctx->getWarningEmittedAt());
    }

    public function testGetExpiresAtParsesIso8601(): void
    {
        $ctx = new ActivationContext($this->fakeStorage([
            'expires_at' => '2026-06-02T23:00:00+00:00',
        ]));

        $dt = $ctx->getExpiresAt();
        self::assertNotNull($dt);
        self::assertSame('2026-06-02T23:00:00+00:00', $dt->format(\DateTimeInterface::ATOM));
    }

    public function testGetOriginalTtlMinutesReturnsInt(): void
    {
        $ctx = new ActivationContext($this->fakeStorage([
            'original_ttl_minutes' => 60,
        ]));

        self::assertSame(60, $ctx->getOriginalTtlMinutes());
    }

    public function testGetWarningEmittedAtParsesIso8601(): void
    {
        $ctx = new ActivationContext($this->fakeStorage([
            'warning_emitted_at' => '2026-06-02T22:00:00+00:00',
        ]));

        $dt = $ctx->getWarningEmittedAt();
        self::assertNotNull($dt);
        self::assertSame('2026-06-02T22:00:00+00:00', $dt->format(\DateTimeInterface::ATOM));
    }

    public function testUpdateExpiryWritesFieldsWithoutTouchingReason(): void
    {
        $storage = $this->fakeStorage(['reason' => 'deploy', 'retry_after' => 300]);
        $ctx = new ActivationContext($storage);

        $expiresAt = new \DateTimeImmutable('2026-06-02T23:00:00+00:00');
        $ctx->updateExpiry($expiresAt, 60, null);

        self::assertSame(60, $ctx->getOriginalTtlMinutes());
        $stored = $ctx->getExpiresAt();
        self::assertNotNull($stored);
        self::assertSame('2026-06-02T23:00:00+00:00', $stored->format(\DateTimeInterface::ATOM));
        self::assertNull($ctx->getWarningEmittedAt());

        self::assertSame('deploy', $ctx->getReason());
        self::assertSame(300, $ctx->getRetryAfter());
    }

    public function testUpdateExpiryCanSetWarningEmittedAt(): void
    {
        $storage = $this->fakeStorage([
            'expires_at'           => '2026-06-02T23:00:00+00:00',
            'original_ttl_minutes' => 60,
        ]);
        $ctx = new ActivationContext($storage);

        $warnedAt = new \DateTimeImmutable('2026-06-02T22:50:00+00:00');
        $ctx->updateExpiry(
            new \DateTimeImmutable('2026-06-02T23:00:00+00:00'),
            60,
            $warnedAt,
        );

        $result = $ctx->getWarningEmittedAt();
        self::assertNotNull($result);
        self::assertSame('2026-06-02T22:50:00+00:00', $result->format(\DateTimeInterface::ATOM));
    }

    public function testSetStoresTtlFieldsWhenProvided(): void
    {
        $storage = $this->fakeStorage();
        $ctx = new ActivationContext($storage);

        $ctx->set(
            reason: 'release',
            retryAfter: 60,
            activatedByScheduleWindowId: null,
            expectedEndAt: null,
            activatedByHealthCheckFailure: false,
            activatedByHistoryRecordId: null,
            expiresAt: '2026-06-02T23:00:00+00:00',
            originalTtlMinutes: 60,
            warningEmittedAt: null,
        );

        self::assertSame(60, $ctx->getOriginalTtlMinutes());
        self::assertNotNull($ctx->getExpiresAt());
    }

    public function testGetScopeReturnsNullByDefault(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        self::assertNull($ctx->getScope());
    }

    public function testSetScopeAndReadBack(): void
    {
        $ctx   = new ActivationContext($this->fakeStorage());
        $scope = new MaintenanceScope(['/shop', '/api'], [2]);

        $ctx->setScope($scope);

        $result = $ctx->getScope();
        self::assertNotNull($result);
        self::assertSame(['/shop', '/api'], $result->pathPrefixes);
        self::assertSame([2], $result->siteIds);
    }

    public function testSetScopeNullClearsScope(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $ctx->setScope(new MaintenanceScope(['/shop'], []));
        $ctx->setScope(null);

        self::assertNull($ctx->getScope());
    }

    public function testClearWipesScopeAlso(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $ctx->set('reason', 300);
        $ctx->setScope(new MaintenanceScope(['/shop'], [2]));

        $ctx->clear();

        self::assertNull($ctx->getScope());
    }
}
