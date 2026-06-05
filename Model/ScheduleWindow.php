<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ScheduleWindow
{
    public function __construct(
        public string $id,
        public string $timezone,
        public ?string $reason,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public ?string $cronExpression,
        public ?int $durationMinutes,
        public int $announceBeforeMinutes = 0,
        public int $createdByUserId = 0,
        public string $createdByUsername = '',
    ) {
        $hasOneTime = ($from !== null && $to !== null);
        $hasRecurring = ($cronExpression !== null && $durationMinutes !== null);

        if ($hasOneTime === $hasRecurring) {
            throw new InvalidArgumentException(
                'ScheduleWindow requires exactly one of (from+to) or (cronExpression+durationMinutes).'
            );
        }
    }

    public function isRecurring(): bool
    {
        return $this->cronExpression !== null;
    }

    public function isActiveAt(DateTimeImmutable $now): bool
    {
        if (!$this->isRecurring()) {
            assert($this->from !== null && $this->to !== null);

            return $now >= $this->from && $now <= $this->to;
        }

        $diff = $now->getTimestamp() - $this->lastFireAt($now)->getTimestamp();

        return $diff >= 0 && $diff < ($this->durationMinutes * 60);
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        if ($this->isRecurring()) {
            return false;
        }

        assert($this->to !== null);

        return $now > $this->to;
    }

    public function computeExpectedEndAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if (!$this->isRecurring()) {
            return $this->to;
        }

        return $this->lastFireAt($now)->modify('+' . $this->durationMinutes . ' minutes');
    }

    private function lastFireAt(DateTimeImmutable $now): DateTimeImmutable
    {
        $tz = new DateTimeZone($this->timezone);
        $cron = new CronExpression($this->cronExpression);
        $lastFire = $cron->getPreviousRunDate($now->setTimezone($tz), 0, true);

        return DateTimeImmutable::createFromMutable($lastFire)->setTimezone(new DateTimeZone('UTC'));
    }
}
