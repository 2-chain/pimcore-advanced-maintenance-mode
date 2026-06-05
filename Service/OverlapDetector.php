<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;

final class OverlapDetector
{
    /**
     * Returns windows from $existing that overlap with $new.
     *
     * @param  ScheduleWindow[] $existing
     * @return ScheduleWindow[]
     */
    public function detect(ScheduleWindow $new, array $existing): array
    {
        $overlaps = [];
        foreach ($existing as $w) {
            if ($w->id === $new->id) {
                continue;
            }
            if ($this->overlaps($new, $w)) {
                $overlaps[] = $w;
            }
        }
        return $overlaps;
    }

    private function overlaps(ScheduleWindow $a, ScheduleWindow $b): bool
    {
        // Two recurring windows: conservative — always warn.
        if ($a->isRecurring() && $b->isRecurring()) {
            return true;
        }

        // One-time vs one-time: range intersection.
        if (!$a->isRecurring() && !$b->isRecurring()) {
            assert($a->from !== null && $a->to !== null && $b->from !== null && $b->to !== null);
            return $a->from < $b->to && $b->from < $a->to;
        }

        // One-time vs recurring: probe at from, midpoint, to.
        [$oneTime, $recurring] = $a->isRecurring() ? [$b, $a] : [$a, $b];
        assert($oneTime->from !== null && $oneTime->to !== null);

        $mid = (new \DateTimeImmutable())->setTimestamp(
            (int)(($oneTime->from->getTimestamp() + $oneTime->to->getTimestamp()) / 2)
        );

        foreach ([$oneTime->from, $mid, $oneTime->to] as $probe) {
            if ($recurring->isActiveAt($probe)) {
                return true;
            }
        }
        return false;
    }
}
