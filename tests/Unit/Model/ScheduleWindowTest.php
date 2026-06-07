<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class ScheduleWindowTest extends TestCase
{
    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testOneTimeIsActiveWhenNowIsBetweenFromAndTo(): void
    {
        $w = new ScheduleWindow('w1', 'UTC', 'deploy', $this->utc('2026-06-02T02:00:00Z'), $this->utc('2026-06-02T04:00:00Z'), null, null);

        self::assertTrue($w->isActiveAt($this->utc('2026-06-02T03:00:00Z')));
    }

    public function testOneTimeIsNotActiveBeforeFrom(): void
    {
        $w = new ScheduleWindow('w1', 'UTC', null, $this->utc('2026-06-02T02:00:00Z'), $this->utc('2026-06-02T04:00:00Z'), null, null);

        self::assertFalse($w->isActiveAt($this->utc('2026-06-02T01:59:59Z')));
    }

    public function testOneTimeIsNotActiveAfterTo(): void
    {
        $w = new ScheduleWindow('w1', 'UTC', null, $this->utc('2026-06-02T02:00:00Z'), $this->utc('2026-06-02T04:00:00Z'), null, null);

        self::assertFalse($w->isActiveAt($this->utc('2026-06-02T04:00:01Z')));
    }

    public function testOneTimeIsExpiredWhenNowIsAfterTo(): void
    {
        $w = new ScheduleWindow('w1', 'UTC', null, $this->utc('2026-06-02T02:00:00Z'), $this->utc('2026-06-02T04:00:00Z'), null, null);

        self::assertTrue($w->isExpired($this->utc('2026-06-02T04:00:01Z')));
        self::assertFalse($w->isExpired($this->utc('2026-06-02T03:00:00Z')));
    }

    public function testOneTimeComputeExpectedEndAt(): void
    {
        $to = $this->utc('2026-06-02T04:00:00Z');
        $w  = new ScheduleWindow('w1', 'UTC', null, $this->utc('2026-06-02T02:00:00Z'), $to, null, null);

        self::assertEquals($to, $w->computeExpectedEndAt($this->utc('2026-06-02T03:00:00Z')));
    }

    public function testRecurringIsActiveWhenNowIsWithinDuration(): void
    {
        // fires at 02:00 UTC daily; duration 60 min
        $w = new ScheduleWindow('w2', 'UTC', 'nightly', null, null, '0 2 * * *', 60);

        // 02:30 → within 60 min of last fire at 02:00
        self::assertTrue($w->isActiveAt($this->utc('2026-06-02T02:30:00Z')));
    }

    public function testRecurringIsNotActiveAfterDurationExpires(): void
    {
        $w = new ScheduleWindow('w2', 'UTC', null, null, null, '0 2 * * *', 60);

        // 03:01 → 61 min after last fire at 02:00
        self::assertFalse($w->isActiveAt($this->utc('2026-06-02T03:01:00Z')));
    }

    public function testRecurringIsNeverExpired(): void
    {
        $w = new ScheduleWindow('w2', 'UTC', null, null, null, '0 2 * * *', 60);

        self::assertFalse($w->isExpired($this->utc('2099-01-01T00:00:00Z')));
    }

    public function testRecurringComputeExpectedEndAt(): void
    {
        // fires at 02:00; duration 60 min → expected end 03:00
        $w = new ScheduleWindow('w2', 'UTC', null, null, null, '0 2 * * *', 60);

        $end = $w->computeExpectedEndAt($this->utc('2026-06-02T02:30:00Z'));
        self::assertNotNull($end);
        self::assertSame('2026-06-02T03:00:00+00:00', $end->format(DateTimeInterface::ATOM));
    }

    public function testCreatedByFieldsAreStored(): void
    {
        $window = new ScheduleWindow('win-user', 'UTC', null, $this->utc('2026-06-02T10:00:00Z'), $this->utc('2026-06-02T11:00:00Z'), null, null, 0, 7, 'admin');

        self::assertSame(7, $window->createdByUserId);
        self::assertSame('admin', $window->createdByUsername);
    }

    public function testInvalidInvariantThrowsOnBothSetsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScheduleWindow('w3', 'UTC', null, null, null, null, null);
    }

    public function testInvalidInvariantThrowsOnBothSetsPopulated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScheduleWindow(
            'w3',
            'UTC',
            null,
            $this->utc('2026-06-02T02:00:00Z'),
            $this->utc('2026-06-02T04:00:00Z'),
            '0 2 * * *',
            60
        );
    }
}
