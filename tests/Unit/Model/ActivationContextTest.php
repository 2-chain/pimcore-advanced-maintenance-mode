<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;

final class ActivationContextTest extends TestCase
{
    private function fakeStorage(): ContextStorageInterface
    {
        return new class implements ContextStorageInterface {
            private array $state = [
                'reason'                              => null,
                'retry_after'                         => null,
                'activated_by_schedule_window_id'     => null,
                'expected_end_at'                     => null,
                'activated_by_health_check_failure'   => false,
                'activated_by_history_record_id'      => null,
            ];

            #[\Override] public function load(): array { return $this->state; }

            #[\Override] public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
            ): void {
                $this->state = [
                    'reason'                              => $reason,
                    'retry_after'                         => $retryAfter,
                    'activated_by_schedule_window_id'     => $activatedByScheduleWindowId,
                    'expected_end_at'                     => $expectedEndAt,
                    'activated_by_health_check_failure'   => $activatedByHealthCheckFailure,
                    'activated_by_history_record_id'      => $activatedByHistoryRecordId,
                ];
            }

            #[\Override] public function clear(): void
            {
                $this->state = [
                    'reason'                              => null,
                    'retry_after'                         => null,
                    'activated_by_schedule_window_id'     => null,
                    'expected_end_at'                     => null,
                    'activated_by_health_check_failure'   => false,
                    'activated_by_history_record_id'      => null,
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

    public function testNewFieldsDefaultToNullAndFalse(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());

        self::assertNull($ctx->getActivatedByScheduleWindowId());
        self::assertNull($ctx->getExpectedEndAt());
        self::assertFalse($ctx->isActivatedByHealthCheckFailure());
    }

    public function testSetWithScheduleWindowFields(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $end = new \DateTimeImmutable('2026-06-02T03:00:00+00:00');

        $ctx->set('Scheduled DB dump', null, 'win-abc', $end, false);

        self::assertSame('win-abc', $ctx->getActivatedByScheduleWindowId());
        self::assertEquals($end, $ctx->getExpectedEndAt());
        self::assertFalse($ctx->isActivatedByHealthCheckFailure());
        self::assertSame('Scheduled DB dump', $ctx->getReason());
    }

    public function testClearAlsoWipesScheduleFields(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $ctx->set('x', null, 'win-1', new \DateTimeImmutable('2026-06-02T03:00:00+00:00'));

        $ctx->clear();

        self::assertNull($ctx->getActivatedByScheduleWindowId());
        self::assertNull($ctx->getExpectedEndAt());
    }

    public function testHistoryRecordIdDefaultsToNull(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        self::assertNull($ctx->getActivatedByHistoryRecordId());
    }

    public function testSetActivatedByHistoryRecordIdWritesThroughStorage(): void
    {
        $ctx = new ActivationContext($this->fakeStorage());
        $ctx->setActivatedByHistoryRecordId(42);
        self::assertSame(42, $ctx->getActivatedByHistoryRecordId());
    }
}
