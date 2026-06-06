<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Detector\OverlapDetector;

final class OverlapDetectorTest extends TestCase
{
    private function utc(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    private function ot(string $id, string $from, string $to): ScheduleWindow
    {
        return new ScheduleWindow($id, 'UTC', null, $this->utc($from), $this->utc($to), null, null);
    }

    public function testNoOverlapReturnEmpty(): void
    {
        $d = new OverlapDetector();
        $new = $this->ot('n', '2026-06-02T06:00:00Z', '2026-06-02T08:00:00Z');
        $existing = [$this->ot('e1', '2026-06-02T02:00:00Z', '2026-06-02T04:00:00Z')];

        self::assertSame([], $d->detect($new, $existing));
    }

    public function testOverlapDetected(): void
    {
        $d = new OverlapDetector();
        $new = $this->ot('n', '2026-06-02T03:00:00Z', '2026-06-02T05:00:00Z');
        $existing = [$this->ot('e1', '2026-06-02T02:00:00Z', '2026-06-02T04:00:00Z')];

        $overlaps = $d->detect($new, $existing);
        self::assertCount(1, $overlaps);
        self::assertSame('e1', $overlaps[0]->id);
    }

    public function testNewWindowExcludedFromOwnOverlapCheck(): void
    {
        $d = new OverlapDetector();
        $new = $this->ot('same-id', '2026-06-02T02:00:00Z', '2026-06-02T04:00:00Z');
        $existing = [$new]; // same ID — editing existing

        self::assertSame([], $d->detect($new, $existing));
    }
}
