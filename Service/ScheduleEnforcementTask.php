<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Attribute\WithMonologChannel;
use Override;
use Pimcore\Maintenance\TaskInterface;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ScheduleHistoryRepositoryInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\SkipStorage;

#[WithMonologChannel('advanced_maintenance_mode')]
final class ScheduleEnforcementTask implements TaskInterface
{
    private const SESSION_ID = 'schedule-enforcement-task';

    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly ScheduleStorage $storage,
        private readonly QueuedWindowStorageInterface $queue,
        private readonly LoggerInterface $logger,
        private readonly ScheduleHistoryRepositoryInterface $historyRepo,
        private readonly SkipStorage $skipStorage,
    ) {}

    #[Override]
    public function execute(): void
    {
        $this->executeAtTime(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function executeAtTime(DateTimeImmutable $now): void
    {
        $all = $this->storage->findAll();

        $modeOn        = $this->helper->isActive();
        $ownerWindowId = $this->context->getActivatedByScheduleWindowId();

        // Stale-context guard: if maintenance is off but context still names an owner
        // (e.g. deactivate() succeeded but clear() never ran, or the marker file was
        // wiped on a restart while TmpStore survived), purge the ghost before any
        // case logic runs — otherwise Case B will never fire on re-activation.
        if (!$modeOn && $ownerWindowId !== null) {
            $this->logger->debug('Clearing stale schedule context (maintenance is off)', ['staleWindowId' => $ownerWindowId]);
            $this->context->clear();
            $ownerWindowId = null;
        }

        // GC: remove expired one-time windows; recover any orphaned history records
        $live = [];
        foreach ($all as $w) {
            if ($w->isExpired($now)) {
                $this->logger->debug('GC: removing expired schedule window', ['id' => $w->id]);
                $orphanedId = $this->historyRepo->findInProgressIdByWindowId($w->id);
                if ($orphanedId !== null) {
                    $this->historyRepo->updateEnd($orphanedId, $now, 'schedule');
                    $this->logger->info('Closed orphaned in-progress history record for expired window', ['windowId' => $w->id, 'historyId' => $orphanedId]);
                    // If context was lost (null ownerWindowId) and maintenance is still on,
                    // deactivate it — the scheduled window has expired.
                    if ($modeOn && $ownerWindowId === null) {
                        $this->helper->deactivate();
                        $this->context->clear();
                        $modeOn = false;
                        $this->logger->warning('Deactivated maintenance: context was orphaned (no ownerWindowId) but expired window had an open history record', ['windowId' => $w->id]);
                    }
                }
            } else {
                $live[] = $w;
            }
        }
        if (count($live) !== count($all)) {
            $this->storage->replaceAll($live);
        }

        $active = array_values(array_filter($live, static fn(ScheduleWindow $w) => $w->isActiveAt($now)));

        if ($active !== [] && !$modeOn) {
            // Case A: activate
            $primary = $this->pickPrimary($active, $now);

            if ($this->skipStorage->shouldSkip($primary->id, $now)) {
                return;
            }

            $this->helper->activate(self::SESSION_ID);
            $this->context->set(
                $primary->reason,
                null,
                $primary->id,
                $primary->computeExpectedEndAt($now),
            );
            $historyId = $this->historyRepo->insertStart(
                scheduleWindowId: $primary->id,
                startedAt: $now,
                type: $primary->isRecurring() ? 'recurring' : 'one-time',
                reason: $primary->reason,
                configuredDurationMinutes: $primary->durationMinutes,
            );
            $this->context->setActivatedByHistoryRecordId($historyId);
            $this->logger->info('Maintenance mode activated by schedule window', ['id' => $primary->id]);
            return;
        }

        if ($active === [] && $modeOn && $ownerWindowId !== null) {
            // Case B: scheduled window ended — check queue first
            $nextId = $this->queue->dequeueEarliest();
            if ($nextId !== null) {
                $next = $this->storage->findById($nextId);
                if ($next !== null && $next->isActiveAt($now)) {
                    $this->context->set($next->reason, null, $next->id, $next->computeExpectedEndAt($now));
                    $this->logger->info('Active schedule window changed from queue', ['id' => $next->id]);
                    return;
                }
            }
            $this->helper->deactivate();
            $historyId = $this->context->getActivatedByHistoryRecordId();
            if ($historyId !== null) {
                $this->historyRepo->updateEnd($historyId, $now, 'schedule');
            }
            $this->context->clear();
            $this->logger->info('Maintenance mode deactivated by schedule window', ['id' => $ownerWindowId]);
            return;
        }

        if ($active !== [] && $modeOn && $ownerWindowId === null) {
            // Case C: manual maintenance in progress — queue windows
            $queued = $this->queue->all();
            foreach ($active as $w) {
                if (!in_array($w->id, $queued, true)) {
                    $this->queue->enqueue($w->id);
                }
            }
            return;
        }

        if ($active !== [] && $modeOn && $ownerWindowId !== null) {
            // Case E: check if primary window changed
            $primary = $this->pickPrimary($active, $now);
            if ($primary->id !== $ownerWindowId) {
                $historyId = $this->context->getActivatedByHistoryRecordId();
                if ($historyId !== null) {
                    $this->historyRepo->updateEnd($historyId, $now, 'schedule');
                }
                $newHistoryId = $this->historyRepo->insertStart(
                    scheduleWindowId: $primary->id,
                    startedAt: $now,
                    type: $primary->isRecurring() ? 'recurring' : 'one-time',
                    reason: $primary->reason,
                    configuredDurationMinutes: $primary->durationMinutes,
                );
                $this->context->set($primary->reason, null, $primary->id, $primary->computeExpectedEndAt($now));
                $this->context->setActivatedByHistoryRecordId($newHistoryId);
                $this->logger->info('Active schedule window changed', ['old' => $ownerWindowId, 'new' => $primary->id]);
            }
            return;
        }

        if ($active === [] && $modeOn && $ownerWindowId === null) {
            // Case F: maintenance is on, no active schedule windows, no known schedule owner.
            // This happens when the owner window ID was lost from context (e.g. a previous
            // activation's context was partially cleared) while the maintenance flag survived.
            // If context still holds a history record that is still open, the maintenance was
            // schedule-activated and is now orphaned — close the record and deactivate.
            // If there is no such record this is likely manual maintenance; leave it alone.
            $historyId = $this->context->getActivatedByHistoryRecordId();
            if ($historyId !== null && $this->historyRepo->isInProgress($historyId)) {
                $this->historyRepo->updateEnd($historyId, $now, 'schedule');
                $this->helper->deactivate();
                $this->context->clear();
                $this->logger->warning(
                    'Deactivated maintenance: orphaned schedule activation (ownerWindowId lost, history still open)',
                    ['historyId' => $historyId]
                );
            }
            return;
        }

        // Case D: nothing to do
    }

    /** @param ScheduleWindow[] $active */
    private function pickPrimary(array $active, DateTimeImmutable $now): ScheduleWindow
    {
        usort($active, static function (ScheduleWindow $a, ScheduleWindow $b) use ($now): int {
            // One-time windows first (sort by $from asc)
            if (!$a->isRecurring() && !$b->isRecurring()) {
                assert($a->from !== null && $b->from !== null);
                return $a->from <=> $b->from;
            }
            if (!$a->isRecurring()) {
                return -1;
            }
            if (!$b->isRecurring()) {
                return 1;
            }
            // Both recurring: sort by expected end asc
            $endA = $a->computeExpectedEndAt($now)?->getTimestamp() ?? 0;
            $endB = $b->computeExpectedEndAt($now)?->getTimestamp() ?? 0;
            return $endA <=> $endB;
        });
        return $active[0];
    }
}
