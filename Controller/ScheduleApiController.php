<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Controller;

use Cron\CronExpression;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Pimcore\Controller\UserAwareController;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto\ActivationContextDto;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto\HistoryRecordDto;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto\NextWindowDto;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Dto\ScheduleWindowDto;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Entity\ScheduleHistoryRecord;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ScheduleHistoryRepositoryInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\SkipStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Detector\OverlapDetector;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PendingHealthCheckStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider;

class ScheduleApiController extends UserAwareController
{
    private const int OVERRUN_THRESHOLD_PERCENT = 20;

    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $activationContext,
        private readonly ScheduleStorage $scheduleStorage,
        private readonly BundleConfiguration $config,
        private readonly OverlapDetector $overlapDetector,
        private readonly ScheduleHistoryRepositoryInterface $historyRepo,
        private readonly SkipStorage $skipStorage,
        private readonly QueuedWindowStorageInterface $queuedWindowStorage,
        private readonly CompiledRulesProvider $rulesProvider,
        private readonly PreAnnounceStorage $preAnnounceStorage,
        private readonly ?PendingHealthCheckStorage $pendingStorage = null,
    ) {}

    protected function isAllowedToManage(): bool
    {
        $user = $this->getPimcoreUser();

        return $user !== null && $user->isAllowed('advanced_maintenance_manage');
    }

    #[Route('/admin/advanced-maintenance-mode/schedules', name: 'advanced_maintenance_schedules', methods: ['GET'])]
    public function schedules(): JsonResponse
    {
        $now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $windows  = $this->scheduleStorage->findAll();
        $activeId = $this->activationContext->getActivatedByScheduleWindowId();
        $queuedIds = $this->queuedWindowStorage->all();

        $rows = array_map(function (ScheduleWindow $w) use ($windows, $activeId, $now, $queuedIds): array {
            $others   = array_filter($windows, fn(ScheduleWindow $o) => $o->id !== $w->id);
            $overlaps = $this->overlapDetector->detect($w, array_values($others));

            $nextFires = [];
            if ($w->cronExpression !== null) {
                try {
                    $cron = new CronExpression($w->cronExpression);
                    $nextFires = array_map(
                        static fn(DateTime $d) => DateTimeImmutable::createFromMutable($d)->format(DateTimeInterface::ATOM),
                        $cron->getMultipleRunDates(5, $now, false, false, $w->timezone),
                    );
                } catch (Throwable) {
                }
            }

            $dto = new ScheduleWindowDto(
                id:                    $w->id,
                type:                  $w->isRecurring() ? 'recurring' : 'one-time',
                timezone:              $w->timezone,
                reason:                $w->reason,
                from:                  $w->from?->format(DateTimeInterface::ATOM),
                to:                    $w->to?->format(DateTimeInterface::ATOM),
                cronExpression:        $w->cronExpression,
                durationMinutes:       $w->durationMinutes,
                announceBeforeMinutes: $w->announceBeforeMinutes,
                createdByUserId:       $w->createdByUserId,
                createdByUsername:     $w->createdByUsername,
                activeNow:             $activeId === $w->id,
                queued:                in_array($w->id, $queuedIds, true),
                overlappingWith:       array_map(static fn(ScheduleWindow $o) => $o->id, $overlaps),
                nextFires:             $nextFires,
            );

            $row = $dto->toArray();
            $row['scope'] = $this->scopeToArray($w->scope);
            return $row;
        }, $windows);

        $activationDto = new ActivationContextDto(
            activatedByScheduleWindowId: $this->activationContext->getActivatedByScheduleWindowId(),
            reason:                      $this->activationContext->getReason(),
            retryAfter:                  $this->activationContext->getRetryAfter(),
            expectedEndAt:               $this->activationContext->getExpectedEndAt()?->format(DateTimeInterface::ATOM),
        );

        $manual = $this->preAnnounceStorage->load();
        $preAnnounce = ($manual !== null && $manual->at > $now)
            ? [
                'at'                   => $manual->at->format(DateTimeInterface::ATOM),
                'timezone'             => $manual->timezone,
                'reason'               => $manual->reason,
                'announceBeforeMinutes' => $manual->announceBeforeMinutes,
            ]
            : null;

        return $this->json([
            'windows'           => $rows,
            'currentActivation' => $activationDto->toArray(),
            'preAnnounce'       => $preAnnounce,
        ]);
    }

    #[Route('/maintenance-status', name: 'advanced_maintenance_public_status', methods: ['GET'])]
    public function publicStatus(Request $request): JsonResponse
    {
        if (!$this->config->publicStatusEnabled) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($this->config->publicStatusToken !== null) {
            $bearer = $request->headers->get('Authorization', '');
            $token  = str_starts_with($bearer, 'Bearer ') ? substr($bearer, 7) : '';
            if (!hash_equals($this->config->publicStatusToken, $token)) {
                return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $maintenance = $this->helper->isActive();
        $now         = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $nextWindow = null;
        foreach ($this->scheduleStorage->findAll() as $w) {
            if (!$w->isActiveAt($now) && !$w->isExpired($now) && !$w->isRecurring()) {
                if ($nextWindow === null || $w->from < $nextWindow->from) {
                    $nextWindow = $w;
                }
            }
        }

        $nextWindowDto = $nextWindow !== null
            ? new NextWindowDto(reason: $nextWindow->reason, startsAt: $nextWindow->from?->format(DateTimeInterface::ATOM))
            : null;

        return $this->json([
            'maintenance'    => $maintenance,
            'reason'         => $maintenance ? $this->activationContext->getReason() : null,
            'activatedAt'    => null,
            'endsAt'         => $maintenance ? $this->activationContext->getExpectedEndAt()?->format(DateTimeInterface::ATOM) : null,
            'preAnnounce'    => false,
            'preAnnounceAt'  => null,
            'nextWindow'     => $nextWindowDto?->toArray(),
            'scope'          => $this->scopeToArray($this->activationContext->getScope()),
        ]);
    }

    #[Route('/admin/advanced-maintenance-mode/schedules', name: 'advanced_maintenance_schedules_create', methods: ['POST'])]
    public function createSchedule(Request $request): JsonResponse
    {
        if (!$this->isAllowedToManage()) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type = $body['type'] ?? null;
        if ($type !== 'one-time' && $type !== 'recurring') {
            return $this->json(['error' => 'Invalid type; must be "one-time" or "recurring"'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $timezone = $body['timezone'] ?? 'UTC';
        try {
            $tzObj = new \DateTimeZone($timezone);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid timezone'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fromRaw = $body['from'] ?? null;
        try {
            $from = $fromRaw !== null ? new DateTimeImmutable($fromRaw, $tzObj) : null;
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid "from" datetime'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $toRaw = $body['to'] ?? null;
        try {
            $to = $toRaw !== null ? new DateTimeImmutable($toRaw, $tzObj) : null;
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid "to" datetime'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cronExpression  = $body['cronExpression'] ?? null;
        $durationMinutes = isset($body['durationMinutes']) ? (int) $body['durationMinutes'] : null;
        $reason                = $body['reason'] ?? null;
        $announceBeforeMinutes = (int) ($body['announceBeforeMinutes'] ?? 0);
        $forceCreate           = (bool) ($body['forceCreate'] ?? false);

        $pathPrefixes = \array_values(\array_filter($body['pathPrefixes'] ?? [], 'is_string'));
        $siteIds      = \array_values(\array_map('intval', \array_filter($body['siteIds'] ?? [], 'is_numeric')));
        $scope        = (!empty($pathPrefixes) || !empty($siteIds))
            ? new MaintenanceScope($pathPrefixes, $siteIds)
            : null;

        $pimcoreUser       = $this->getPimcoreUser();
        $createdByUserId   = (int) ($pimcoreUser?->getId() ?? 0);
        $createdByUsername = (string) ($pimcoreUser?->getName() ?? '');

        try {
            $window = new ScheduleWindow(
                id: bin2hex(random_bytes(16)),
                timezone: $timezone,
                reason: $reason,
                from: $from,
                to: $to,
                cronExpression: $cronExpression,
                durationMinutes: $durationMinutes,
                announceBeforeMinutes: $announceBeforeMinutes,
                createdByUserId: $createdByUserId,
                createdByUsername: $createdByUsername,
                scope: $scope,
            );
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$forceCreate) {
            $overlaps = $this->overlapDetector->detect($window, $this->scheduleStorage->findAll());
            if ($overlaps !== []) {
                return $this->json([
                    'overlapping' => array_map(static fn(ScheduleWindow $w) => $w->id, $overlaps),
                ], Response::HTTP_CONFLICT);
            }
        }

        $this->scheduleStorage->add($window);

        return $this->json(['id' => $window->id], Response::HTTP_CREATED);
    }

    #[Route('/admin/advanced-maintenance-mode/schedules/{id}', name: 'advanced_maintenance_schedules_delete', methods: ['DELETE'])]
    public function deleteSchedule(string $id): JsonResponse
    {
        if (!$this->isAllowedToManage()) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $window = $this->scheduleStorage->findById($id);
        if ($window === null) {
            return $this->json(['error' => 'Schedule not found'], Response::HTTP_NOT_FOUND);
        }

        if ($this->activationContext->getActivatedByScheduleWindowId() === $id) {
            return $this->json(['error' => 'Cannot delete an active schedule window'], Response::HTTP_CONFLICT);
        }

        $this->scheduleStorage->remove($id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/admin/advanced-maintenance-mode/schedules/{id}/end-now', name: 'advanced_maintenance_schedules_end_now', methods: ['POST'])]
    public function endNow(string $id): JsonResponse
    {
        if (!$this->isAllowedToManage()) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $window = $this->scheduleStorage->findById($id);
        if ($window === null) {
            return $this->json(['error' => 'Schedule not found'], Response::HTTP_NOT_FOUND);
        }

        if ($this->activationContext->getActivatedByScheduleWindowId() !== $id) {
            return $this->json(['error' => 'Schedule window is not currently active'], Response::HTTP_CONFLICT);
        }

        $historyId = $this->activationContext->getActivatedByHistoryRecordId();

        $this->helper->deactivate();
        $this->pendingStorage?->write('end_now');
        $this->activationContext->clear();

        if ($historyId !== null) {
            $this->historyRepo->updateEnd($historyId, new DateTimeImmutable('now', new DateTimeZone('UTC')), 'manual');
        }

        $skipUntil = $window->to ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+24 hours');
        $this->skipStorage->skip($id, $skipUntil);
        $this->scheduleStorage->remove($id);

        return $this->json(['historyId' => $historyId]);
    }

    #[Route('/admin/advanced-maintenance-mode/schedules/history', name: 'advanced_maintenance_schedules_history', methods: ['GET'])]
    public function getHistory(Request $request): JsonResponse
    {
        $page             = max(1, (int) $request->query->get('page', 1));
        $pageSize         = min(100, max(1, (int) $request->query->get('pageSize', 25)));
        $scheduleWindowId = $request->query->get('scheduleWindowId');

        $startedAfterRaw  = $request->query->get('startedAfter');
        $startedBeforeRaw = $request->query->get('startedBefore');

        $startedAfter  = $startedAfterRaw !== null ? new DateTimeImmutable($startedAfterRaw) : null;
        $startedBefore = $startedBeforeRaw !== null ? new DateTimeImmutable($startedBeforeRaw) : null;

        $records = $this->historyRepo->findPaginated($page, $pageSize, $scheduleWindowId, $startedAfter, $startedBefore);
        $total   = $this->historyRepo->count($scheduleWindowId);

        return $this->json([
            'history'  => array_map(fn(ScheduleHistoryRecord $r) => $this->buildHistoryRecordDto($r)->toArray(), $records),
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]);
    }

    private function buildHistoryRecordDto(ScheduleHistoryRecord $r): HistoryRecordDto
    {
        return new HistoryRecordDto(
            id:                        (int) $r->getId(),
            scheduleWindowId:          $r->getScheduleWindowId(),
            startedAt:                 $r->getStartedAt()->format(DateTimeInterface::ATOM),
            endedAt:                   $r->getEndedAt()?->format(DateTimeInterface::ATOM),
            durationMinutes:           $r->getDurationMinutes(),
            configuredDurationMinutes: $r->getConfiguredDurationMinutes(),
            type:                      $r->getType(),
            reason:                    $r->getReason(),
            inProgress:                $r->isInProgress(),
            overrun:                   $this->computeOverrun($r),
            endedReason:               $r->getEndedReason(),
        );
    }

    private function computeOverrun(ScheduleHistoryRecord $r): ?bool
    {
        if ($r->isInProgress() || $r->getDurationMinutes() === null || $r->getConfiguredDurationMinutes() === null) {
            return null;
        }

        return $r->getDurationMinutes() > $r->getConfiguredDurationMinutes() * (1 + self::OVERRUN_THRESHOLD_PERCENT / 100);
    }

    #[Route('/admin/advanced-maintenance-mode/exemptions', name: 'advanced_maintenance_exemptions', methods: ['GET'])]
    public function listExemptions(): JsonResponse
    {
        if (!$this->isAllowedToManage()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $exemptions = [];
        foreach ($this->rulesProvider->getRules() as $rule) {
            $exemptions[] = $this->serializeRule($rule);
        }
        if ($this->config->bypassAuthenticatedAdmins) {
            $exemptions[] = ['id' => 'admin-login',   'type' => 'builtin', 'source' => 'builtin', 'description' => 'Pimcore admin login path'];
            $exemptions[] = ['id' => 'admin-session', 'type' => 'builtin', 'source' => 'builtin', 'description' => 'authenticated Pimcore admin'];
        }
        return $this->json(['exemptions' => $exemptions]);
    }

    private function serializeRule(HttpRule|IpRule|CommandRule $rule): array
    {
        return match (true) {
            $rule instanceof IpRule      => ['id' => $rule->id, 'type' => 'ip',      'source' => $rule->source->value, 'description' => $rule->ipOrCidr],
            $rule instanceof CommandRule => ['id' => $rule->id, 'type' => 'command', 'source' => $rule->source->value, 'description' => $rule->namePattern],
            $rule instanceof HttpRule    => ['id' => $rule->id, 'type' => 'http',    'source' => $rule->source->value, 'description' => $this->describeHttpRule($rule)],
        };
    }

    private function describeHttpRule(HttpRule $rule): string
    {
        $parts = [];
        if ($rule->pathGlob !== null) { $parts[] = 'path=' . $rule->pathGlob; }
        if ($rule->routeName !== null) { $parts[] = 'route=' . $rule->routeName; }
        if ($rule->host !== null) { $parts[] = 'host=' . $rule->host; }
        $desc = implode(', ', $parts);
        if ($rule->methods !== []) {
            $desc .= ($desc !== '' ? ' ' : '') . '[' . implode(',', $rule->methods) . ']';
        }
        return $desc;
    }

    /** @return array{global: bool, pathPrefixes: string[], siteIds: int[]} */
    private function scopeToArray(?MaintenanceScope $scope): array
    {
        if ($scope === null || $scope->isGlobal()) {
            return ['global' => true, 'pathPrefixes' => [], 'siteIds' => []];
        }
        return [
            'global'       => false,
            'pathPrefixes' => $scope->pathPrefixes,
            'siteIds'      => $scope->siteIds,
        ];
    }
}
