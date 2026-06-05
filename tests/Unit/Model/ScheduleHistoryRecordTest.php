<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Model;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Entity\ScheduleHistoryRecord;

final class ScheduleHistoryRecordTest extends TestCase
{
    public function testIsInProgressWhenEndedAtNull(): void
    {
        $record = ScheduleHistoryRecord::create(
            scheduleWindowId: 'win-abc',
            startedAt: new DateTimeImmutable('2026-06-02T10:00:00Z'),
            type: 'one-time',
            reason: 'Deploy',
            configuredDurationMinutes: 60,
        );

        self::assertTrue($record->isInProgress());
    }

    public function testIsNotInProgressWhenEndedAtSet(): void
    {
        $record = ScheduleHistoryRecord::create(
            scheduleWindowId: 'win-abc',
            startedAt: new DateTimeImmutable('2026-06-02T10:00:00Z'),
            type: 'recurring',
            reason: null,
            configuredDurationMinutes: 60,
        );
        $record->setEndedAt(new DateTimeImmutable('2026-06-02T11:00:00Z'));
        $record->setDurationMinutes(60);

        self::assertFalse($record->isInProgress());
    }
}
