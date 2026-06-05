<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Pimcore\Tool\MaintenanceModeHelperInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;

class PreAnnounceBannerProvider
{
    private bool $rendered = false;

    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly PreAnnounceStorage $storage,
        private readonly BundleConfiguration $config,
        private readonly ScheduleStorage $scheduleStorage,
    ) {}

    public function provide(): ?PreAnnounceData
    {
        if ($this->helper->isActive()) {
            return null;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $candidates = [];

        // (a) Manual pre-announcement
        $manual = $this->storage->load();
        if ($manual !== null && $manual->at > $now) {
            $threshold = $this->resolveThreshold($manual->announceBeforeMinutes);
            $diff = $manual->at->getTimestamp() - $now->getTimestamp();
            if ($diff <= $threshold * 60) {
                $candidates[] = ['data' => $manual, 'manual' => true];
            }
        }

        // (b) ScheduleWindow candidates
        foreach ($this->scheduleStorage->findAll() as $window) {
            if (!\is_object($window)) {
                continue;
            }
            $nextStart = $this->resolveNextStart($window, $now);
            if ($nextStart === null || $nextStart <= $now) {
                continue;
            }
            $announceMinutes = \property_exists($window, 'announceBeforeMinutes')
                ? $window->announceBeforeMinutes
                : null;
            $threshold = $this->resolveThreshold($announceMinutes);
            $diff = $nextStart->getTimestamp() - $now->getTimestamp();
            if ($diff <= $threshold * 60) {
                $timezone = \property_exists($window, 'timezone') ? (string) $window->timezone : 'UTC';
                $reason = \property_exists($window, 'reason') ? $window->reason : null;
                $candidates[] = [
                    'data' => new PreAnnounceData(
                        at: $nextStart,
                        timezone: $timezone,
                        reason: \is_string($reason) ? $reason : null,
                        announceBeforeMinutes: \is_int($announceMinutes) ? $announceMinutes : null,
                    ),
                    'manual' => false,
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Sort by at ascending; ties: manual wins
        \usort($candidates, static function (array $a, array $b): int {
            $diff = $a['data']->at->getTimestamp() <=> $b['data']->at->getTimestamp();
            if ($diff !== 0) {
                return $diff;
            }
            return $b['manual'] <=> $a['manual'];
        });

        return $candidates[0]['data'];
    }

    public function wasRendered(): bool
    {
        return $this->rendered;
    }

    public function markRendered(): void
    {
        $this->rendered = true;
    }

    private function resolveThreshold(?int $perEntryMinutes): int
    {
        return $perEntryMinutes ?? $this->config->defaultThresholdMinutes ?? 60;
    }

    private function resolveNextStart(object $window, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (\property_exists($window, 'from') && $window->from instanceof \DateTimeImmutable) {
            return $window->from;
        }
        if (\property_exists($window, 'cronExpression')
            && \is_string($window->cronExpression)
            && $window->cronExpression !== ''
            && \class_exists(\Cron\CronExpression::class)
        ) {
            try {
                $cron = new \Cron\CronExpression($window->cronExpression);
                $next = $cron->getNextRunDate($now->format('Y-m-d H:i:s'), 0, false, 'UTC');
                return \DateTimeImmutable::createFromMutable($next);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}
