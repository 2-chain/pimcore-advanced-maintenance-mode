<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Controller\ScheduleApiController;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ScheduleHistoryRepositoryInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\SkipStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Detector\OverlapDetector;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider;

final class ScheduleApiControllerTest extends TestCase
{
    private function buildWindow(string $id): ScheduleWindow
    {
        return new ScheduleWindow(
            $id, 'UTC', null,
            new \DateTimeImmutable('2026-06-02T10:00:00Z'),
            new \DateTimeImmutable('2026-06-02T11:00:00Z'),
            null, null, 0, 1, 'admin',
        );
    }

    private function makeActivationContext(?string $activatedByWindowId = null, ?int $historyRecordId = null): ActivationContext
    {
        $storage = new class($activatedByWindowId, $historyRecordId) implements ContextStorageInterface {
            public function __construct(private ?string $windowId, private ?int $historyRecordId) {}

            #[\Override]
            public function load(): array
            {
                return [
                    'reason'                             => null,
                    'retry_after'                        => null,
                    'activated_by_schedule_window_id'    => $this->windowId,
                    'expected_end_at'                    => null,
                    'activated_by_health_check_failure'  => false,
                    'activated_by_history_record_id'     => $this->historyRecordId,
                    'expires_at'                         => null,
                    'original_ttl_minutes'               => null,
                    'warning_emitted_at'                 => null,
                ];
            }

            #[\Override]
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
                $this->windowId = $activatedByScheduleWindowId;
            }

            #[\Override]
            public function updateExpiry(
                ?string $expiresAt,
                ?int $originalTtlMinutes,
                ?string $warningEmittedAt,
            ): void {}

            #[\Override]
            public function clear(): void
            {
                $this->windowId = null;
            }

            #[\Override]
            public function saveScope(?array $scopeRaw): void {}
        };

        return new ActivationContext($storage);
    }

    private function makeController(
        bool $userAllowed = true,
        ?ScheduleStorage $scheduleStorage = null,
        ?ActivationContext $activationContext = null,
        ?OverlapDetector $overlapDetector = null,
        ?ScheduleHistoryRepositoryInterface $historyRepo = null,
        ?SkipStorage $skipStorage = null,
        ?QueuedWindowStorageInterface $queuedWindowStorage = null,
        ?CompiledRulesProvider $rulesProvider = null,
        bool $bypassAuthenticatedAdmins = false,
        ?PreAnnounceStorage $preAnnounceStorage = null,
    ): ScheduleApiController {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $config = new BundleConfiguration(
            bypassAuthenticatedAdmins: $bypassAuthenticatedAdmins,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: 60,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: false,
            mailOnMaintenanceEnd: false,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            mailPreAnnounceTemplate: null,
            mailMaintenanceStartTemplate: null,
            mailMaintenanceEndTemplate: null,
            notificationWebhooks: [],
        );
        $scheduleStorage ??= $this->createStub(ScheduleStorage::class);
        $activationContext ??= $this->makeActivationContext();
        $overlapDetector ??= new OverlapDetector();
        $historyRepo ??= $this->createStub(ScheduleHistoryRepositoryInterface::class);
        $skipStorage ??= $this->createStub(SkipStorage::class);
        $queuedWindowStorage ??= $this->createStub(QueuedWindowStorageInterface::class);
        $rulesProvider ??= new CompiledRulesProvider([]);
        $preAnnounceStorage ??= new class extends PreAnnounceStorage {
            public function load(): ?PreAnnounceData { return null; }
        };

        return new class(
            $helper,
            $activationContext,
            $scheduleStorage,
            $config,
            $overlapDetector,
            $historyRepo,
            $skipStorage,
            $queuedWindowStorage,
            $rulesProvider,
            $preAnnounceStorage,
            $userAllowed,
        ) extends ScheduleApiController {
            public function __construct(
                MaintenanceModeHelperInterface $helper,
                ActivationContext $activationContext,
                ScheduleStorage $scheduleStorage,
                BundleConfiguration $config,
                OverlapDetector $overlapDetector,
                ScheduleHistoryRepositoryInterface $historyRepo,
                SkipStorage $skipStorage,
                QueuedWindowStorageInterface $queuedWindowStorage,
                CompiledRulesProvider $rulesProvider,
                PreAnnounceStorage $preAnnounceStorage,
                private readonly bool $allowed,
            ) {
                parent::__construct(
                    $helper,
                    $activationContext,
                    $scheduleStorage,
                    $config,
                    $overlapDetector,
                    $historyRepo,
                    $skipStorage,
                    $queuedWindowStorage,
                    $rulesProvider,
                    $preAnnounceStorage,
                );
            }

            #[\Override]
            protected function isAllowedToManage(): bool
            {
                return $this->allowed;
            }

            #[\Override]
            protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): \Symfony\Component\HttpFoundation\JsonResponse
            {
                return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status, $headers);
            }

            #[\Override]
            protected function getPimcoreUser(bool $proxyUser = false): \Pimcore\Security\User\User|\Pimcore\Model\User|null
            {
                return null;
            }
        };
    }

    public function testCreateScheduleReturns403WhenNotAllowed(): void
    {
        $scheduleStorage = $this->createStub(ScheduleStorage::class);
        $activationContext = $this->makeActivationContext();
        $overlapDetector = new OverlapDetector();

        $controller = $this->makeController(userAllowed: false, scheduleStorage: $scheduleStorage, activationContext: $activationContext, overlapDetector: $overlapDetector);

        $request = Request::create('/admin/advanced-maintenance-mode/schedules', 'POST', [], [], [], [], '{}');
        $response = $controller->createSchedule($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateScheduleReturns409OnOverlap(): void
    {
        $existing = $this->buildWindow('existing-1');

        $scheduleStorage = $this->createMock(ScheduleStorage::class);
        $scheduleStorage->expects($this->once())
            ->method('findAll')
            ->willReturn([$existing]);

        $activationContext = $this->makeActivationContext();
        $overlapDetector = new OverlapDetector();

        $controller = $this->makeController(userAllowed: true, scheduleStorage: $scheduleStorage, activationContext: $activationContext, overlapDetector: $overlapDetector);

        $body = \json_encode([
            'type' => 'one-time',
            'from' => '2026-06-02T10:30:00Z',
            'to'   => '2026-06-02T11:30:00Z',
        ]);
        $request = Request::create('/admin/advanced-maintenance-mode/schedules', 'POST', [], [], [], [], $body);
        $response = $controller->createSchedule($request);

        self::assertSame(409, $response->getStatusCode());

        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('overlapping', $data);
        self::assertContains('existing-1', $data['overlapping']);
    }

    public function testDeleteScheduleReturns404WhenMissing(): void
    {
        $scheduleStorage = $this->createMock(ScheduleStorage::class);
        $scheduleStorage->expects($this->once())
            ->method('findById')
            ->with('nonexistent-id')
            ->willReturn(null);

        $activationContext = $this->makeActivationContext();
        $overlapDetector = new OverlapDetector();

        $controller = $this->makeController(userAllowed: true, scheduleStorage: $scheduleStorage, activationContext: $activationContext, overlapDetector: $overlapDetector);

        $response = $controller->deleteSchedule('nonexistent-id');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeleteScheduleReturns409WhenActive(): void
    {
        $window = $this->buildWindow('active-window-id');

        $scheduleStorage = $this->createMock(ScheduleStorage::class);
        $scheduleStorage->expects($this->once())
            ->method('findById')
            ->with('active-window-id')
            ->willReturn($window);

        $activationContext = $this->makeActivationContext('active-window-id');
        $overlapDetector = new OverlapDetector();

        $controller = $this->makeController(userAllowed: true, scheduleStorage: $scheduleStorage, activationContext: $activationContext, overlapDetector: $overlapDetector);

        $response = $controller->deleteSchedule('active-window-id');

        self::assertSame(409, $response->getStatusCode());
    }

    public function testCreateScheduleReturns422OnInvalidJson(): void
    {
        $scheduleStorage = $this->createStub(ScheduleStorage::class);
        $controller = $this->makeController(userAllowed: true, scheduleStorage: $scheduleStorage);

        $request = Request::create('/admin/advanced-maintenance-mode/schedules', 'POST', [], [], [], [], 'not-json');
        $response = $controller->createSchedule($request);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testCreateScheduleReturns201OnSuccess(): void
    {
        $scheduleStorage = $this->createMock(ScheduleStorage::class);
        $scheduleStorage->method('findAll')->willReturn([]);
        $scheduleStorage->expects($this->once())->method('add');

        $controller = $this->makeController(userAllowed: true, scheduleStorage: $scheduleStorage);

        $body = \json_encode(['type' => 'one-time', 'from' => '2026-06-10T10:00:00Z', 'to' => '2026-06-10T11:00:00Z']);
        $request = Request::create('/admin/advanced-maintenance-mode/schedules', 'POST', [], [], [], [], $body);
        $response = $controller->createSchedule($request);

        self::assertSame(201, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertNotEmpty($data['id']);
    }

    public function testDeleteScheduleReturns403WhenNotAllowed(): void
    {
        $scheduleStorage = $this->createStub(ScheduleStorage::class);
        $controller = $this->makeController(userAllowed: false, scheduleStorage: $scheduleStorage);

        $response = $controller->deleteSchedule('any-id');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteScheduleReturns204OnSuccess(): void
    {
        $window = $this->buildWindow('win-to-delete');

        $scheduleStorage = $this->createMock(ScheduleStorage::class);
        $scheduleStorage->method('findById')->willReturn($window);
        $scheduleStorage->expects($this->once())->method('remove')->with('win-to-delete');

        $controller = $this->makeController(userAllowed: true, scheduleStorage: $scheduleStorage, activationContext: $this->makeActivationContext(null));
        $response = $controller->deleteSchedule('win-to-delete');

        self::assertSame(204, $response->getStatusCode());
    }

    public function testEndNowReturns403WhenNotAllowed(): void
    {
        $controller = $this->makeController(userAllowed: false);
        self::assertSame(403, $controller->endNow('any-id')->getStatusCode());
    }

    public function testEndNowReturns404WhenWindowMissing(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $controller = $this->makeController(scheduleStorage: $storage);
        self::assertSame(404, $controller->endNow('win-missing')->getStatusCode());
    }

    public function testEndNowReturns409WhenWindowNotActive(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findById')->willReturn($this->buildWindow('win-idle'));

        $controller = $this->makeController(scheduleStorage: $storage, activationContext: $this->makeActivationContext('win-other'));
        self::assertSame(409, $controller->endNow('win-idle')->getStatusCode());
    }

    public function testEndNowReturns200WithHistoryId(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findById')->willReturn($this->buildWindow('win-live'));

        $repo = $this->createMock(ScheduleHistoryRepositoryInterface::class);
        $repo->expects($this->once())->method('updateEnd')->with(7);
        $skip = $this->createStub(SkipStorage::class);

        $controller = $this->makeController(
            scheduleStorage: $storage,
            activationContext: $this->makeActivationContext('win-live', 7),
            historyRepo: $repo,
            skipStorage: $skip,
        );
        $response = $controller->endNow('win-live');

        self::assertSame(200, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertSame(7, $data['historyId']);
    }

    public function testGetHistoryReturnsPaginatedResults(): void
    {
        $repo = $this->createStub(ScheduleHistoryRepositoryInterface::class);
        $repo->method('findPaginated')->willReturn([]);
        $repo->method('count')->willReturn(0);

        $controller = $this->makeController(historyRepo: $repo);

        $request = Request::create('/', 'GET', ['page' => '1', 'pageSize' => '25']);
        $response = $controller->getHistory($request);

        self::assertSame(200, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('history', $data);
        self::assertArrayHasKey('total', $data);
        self::assertArrayHasKey('page', $data);
        self::assertArrayHasKey('pageSize', $data);
    }

    public function testListSchedulesIncludesExtendedFields(): void
    {
        // Build a real one-time window
        $window = $this->buildWindow('win-ext');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$window]);

        // QueuedWindowStorage: not queued
        $queued = $this->createStub(QueuedWindowStorageInterface::class);
        $queued->method('all')->willReturn([]);

        $controller = $this->makeController(
            scheduleStorage: $storage,
            activationContext: $this->makeActivationContext(null),
            overlapDetector: new OverlapDetector(),
            queuedWindowStorage: $queued,
        );

        $response = $controller->schedules();

        self::assertSame(200, $response->getStatusCode());
        $data = \json_decode($response->getContent(), true);
        self::assertArrayHasKey('windows', $data);
        $w = $data['windows'][0];
        self::assertArrayHasKey('createdByUserId', $w);
        self::assertArrayHasKey('createdByUsername', $w);
        self::assertArrayHasKey('activeNow', $w);
        self::assertArrayHasKey('queued', $w);
        self::assertArrayHasKey('overlappingWith', $w);
        self::assertArrayHasKey('nextFires', $w);
        self::assertFalse($w['activeNow']);
        self::assertFalse($w['queued']);
        self::assertSame([], $w['overlappingWith']);
        self::assertIsArray($w['nextFires']);
    }

    public function testListSchedulesQueued(): void
    {
        $window = $this->buildWindow('win-q');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$window]);

        $queued = $this->createStub(QueuedWindowStorageInterface::class);
        $queued->method('all')->willReturn(['win-q']);

        $controller = $this->makeController(scheduleStorage: $storage, queuedWindowStorage: $queued);
        $data = \json_decode($controller->schedules()->getContent(), true);

        self::assertTrue($data['windows'][0]['queued']);
    }

    public function testListSchedulesActiveNow(): void
    {
        $window = $this->buildWindow('win-active');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$window]);

        $controller = $this->makeController(
            scheduleStorage: $storage,
            activationContext: $this->makeActivationContext('win-active'),
        );
        $data = \json_decode($controller->schedules()->getContent(), true);

        self::assertTrue($data['windows'][0]['activeNow']);
    }

    public function testListSchedulesRecurringWindowHasFiveNextFires(): void
    {
        $window = new ScheduleWindow('win-cron', 'UTC', null, null, null, '0 2 * * *', 60);

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$window]);

        $controller = $this->makeController(scheduleStorage: $storage);
        $data = \json_decode($controller->schedules()->getContent(), true);

        $nextFires = $data['windows'][0]['nextFires'];
        self::assertCount(5, $nextFires);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $nextFires[0]);
    }

    public function testListExemptionsReturns403WhenNotAllowed(): void
    {
        $controller = $this->makeController(userAllowed: false);
        $response = $controller->listExemptions();
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testListExemptionsReturnsIpAndCommandAndHttpRules(): void
    {
        $rules = CompiledRulesProvider::serialize([
            new IpRule(id: 'ip-0', ipOrCidr: '10.0.0.0/8', source: RuleSource::Yaml),
            new CommandRule(id: 'cmd-0', namePattern: 'pimcore:*', source: RuleSource::Yaml),
            new HttpRule(id: 'http-0', source: RuleSource::Yaml, pathGlob: '/api/health', methods: ['GET']),
        ]);
        $provider = new CompiledRulesProvider($rules);
        $controller = $this->makeController(rulesProvider: $provider);

        $response = $controller->listExemptions();
        $body = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $exemptions = $body['exemptions'];
        $this->assertCount(3, $exemptions);

        $this->assertSame(['id' => 'ip-0', 'type' => 'ip', 'source' => 'yaml', 'description' => '10.0.0.0/8'], $exemptions[0]);
        $this->assertSame(['id' => 'cmd-0', 'type' => 'command', 'source' => 'yaml', 'description' => 'pimcore:*'], $exemptions[1]);
        $this->assertSame(['id' => 'http-0', 'type' => 'http', 'source' => 'yaml', 'description' => 'path=/api/health [GET]'], $exemptions[2]);
    }

    public function testListExemptionsIncludesBuiltinBypassesWhenEnabled(): void
    {
        $controller = $this->makeController(rulesProvider: new CompiledRulesProvider([]), bypassAuthenticatedAdmins: true);

        $response = $controller->listExemptions();
        $body = json_decode($response->getContent(), true);

        $ids = array_column($body['exemptions'], 'id');
        $this->assertContains('admin-login', $ids);
        $this->assertContains('admin-session', $ids);

        $adminLogin = array_values(array_filter($body['exemptions'], fn($e) => $e['id'] === 'admin-login'))[0];
        $this->assertSame('builtin', $adminLogin['type']);
        $this->assertSame('builtin', $adminLogin['source']);
        $this->assertSame('Pimcore admin login path', $adminLogin['description']);

        $adminSession = array_values(array_filter($body['exemptions'], fn($e) => $e['id'] === 'admin-session'))[0];
        $this->assertSame('builtin', $adminSession['type']);
        $this->assertSame('builtin', $adminSession['source']);
        $this->assertSame('authenticated Pimcore admin', $adminSession['description']);
    }

    public function testListExemptionsExcludesBuiltinBypassesWhenDisabled(): void
    {
        $controller = $this->makeController(rulesProvider: new CompiledRulesProvider([]), bypassAuthenticatedAdmins: false);

        $response = $controller->listExemptions();
        $body = json_decode($response->getContent(), true);

        $ids = array_column($body['exemptions'], 'id');
        $this->assertNotContains('admin-login', $ids);
        $this->assertNotContains('admin-session', $ids);
    }

    public function testListSchedulesIncludesPreAnnounceKey(): void
    {
        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([]);

        $at = new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC'));
        $preAnnounceStorage = new class($at) extends PreAnnounceStorage {
            public function __construct(private readonly \DateTimeImmutable $at) {}
            public function load(): ?PreAnnounceData
            {
                return new PreAnnounceData($this->at, 'UTC', 'Deploy', null);
            }
        };

        $controller = $this->makeController(scheduleStorage: $storage, preAnnounceStorage: $preAnnounceStorage);
        $data = \json_decode($controller->schedules()->getContent(), true);

        self::assertArrayHasKey('preAnnounce', $data);
        self::assertNotNull($data['preAnnounce']);
        self::assertSame('Deploy', $data['preAnnounce']['reason']);
    }

    private function makeActivationContextWithScope(?MaintenanceScope $scope): ActivationContext
    {
        $storage = new class($scope) implements ContextStorageInterface {
            private ?array $scopeRaw = null;

            public function __construct(private readonly ?MaintenanceScope $scope) {}

            #[\Override]
            public function load(): array
            {
                $scopeRaw = $this->scope !== null
                    ? ['path_prefixes' => $this->scope->pathPrefixes, 'site_ids' => $this->scope->siteIds]
                    : null;
                return [
                    'reason'                             => null,
                    'retry_after'                        => null,
                    'activated_by_schedule_window_id'    => null,
                    'expected_end_at'                    => null,
                    'activated_by_health_check_failure'  => false,
                    'activated_by_history_record_id'     => null,
                    'expires_at'                         => null,
                    'original_ttl_minutes'               => null,
                    'warning_emitted_at'                 => null,
                    'scope'                              => $scopeRaw,
                ];
            }

            #[\Override]
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
            ): void {}

            #[\Override]
            public function updateExpiry(
                ?string $expiresAt,
                ?int $originalTtlMinutes,
                ?string $warningEmittedAt,
            ): void {}

            #[\Override]
            public function clear(): void {}

            #[\Override]
            public function saveScope(?array $scopeRaw): void
            {
                $this->scopeRaw = $scopeRaw;
            }
        };

        return new ActivationContext($storage);
    }

    public function testGetSchedulesIncludesScopePerWindow(): void
    {
        $scope  = new MaintenanceScope(['/shop'], [42]);
        $window = new ScheduleWindow(
            'win-scope', 'UTC', null,
            new \DateTimeImmutable('2026-06-02T10:00:00Z'),
            new \DateTimeImmutable('2026-06-02T11:00:00Z'),
            null, null, 0, 1, 'admin',
            $scope,
        );

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$window]);

        $queued = $this->createStub(QueuedWindowStorageInterface::class);
        $queued->method('all')->willReturn([]);

        $controller = $this->makeController(
            scheduleStorage: $storage,
            activationContext: $this->makeActivationContext(null),
            overlapDetector: new OverlapDetector(),
            queuedWindowStorage: $queued,
        );

        $data = \json_decode($controller->schedules()->getContent(), true);

        self::assertArrayHasKey('scope', $data['windows'][0]);
        $s = $data['windows'][0]['scope'];
        self::assertFalse($s['global']);
        self::assertSame(['/shop'], $s['pathPrefixes']);
        self::assertSame([42], $s['siteIds']);
    }

    public function testGetSchedulesNullScopeReturnsGlobalScope(): void
    {
        $window = $this->buildWindow('win-no-scope');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$window]);

        $queued = $this->createStub(QueuedWindowStorageInterface::class);
        $queued->method('all')->willReturn([]);

        $controller = $this->makeController(
            scheduleStorage: $storage,
            activationContext: $this->makeActivationContext(null),
            overlapDetector: new OverlapDetector(),
            queuedWindowStorage: $queued,
        );

        $data = \json_decode($controller->schedules()->getContent(), true);

        self::assertArrayHasKey('scope', $data['windows'][0]);
        $s = $data['windows'][0]['scope'];
        self::assertTrue($s['global']);
        self::assertSame([], $s['pathPrefixes']);
        self::assertSame([], $s['siteIds']);
    }

    public function testGetMaintenanceStatusIncludesScope(): void
    {
        $scope = new MaintenanceScope(['/api'], [7]);
        $activationContext = $this->makeActivationContextWithScope($scope);

        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);

        $config = new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: null,
            publicStatusEnabled: true,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: 60,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: false,
            mailOnMaintenanceEnd: false,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            mailPreAnnounceTemplate: null,
            mailMaintenanceStartTemplate: null,
            mailMaintenanceEndTemplate: null,
            notificationWebhooks: [],
        );

        $scheduleStorage = $this->createStub(ScheduleStorage::class);
        $scheduleStorage->method('findAll')->willReturn([]);

        $controller = new class(
            $helper,
            $activationContext,
            $scheduleStorage,
            $config,
            new OverlapDetector(),
            $this->createStub(ScheduleHistoryRepositoryInterface::class),
            $this->createStub(SkipStorage::class),
            $this->createStub(QueuedWindowStorageInterface::class),
            new CompiledRulesProvider([]),
            new class extends PreAnnounceStorage {
                public function load(): ?PreAnnounceData { return null; }
            },
        ) extends ScheduleApiController {
            #[\Override]
            protected function isAllowedToManage(): bool { return true; }

            #[\Override]
            protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): \Symfony\Component\HttpFoundation\JsonResponse
            {
                return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status, $headers);
            }

            #[\Override]
            protected function getPimcoreUser(bool $proxyUser = false): \Pimcore\Security\User\User|\Pimcore\Model\User|null
            {
                return null;
            }
        };

        $request = Request::create('/maintenance-status', 'GET');
        $response = $controller->publicStatus($request);
        $data = \json_decode($response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('scope', $data);
        self::assertFalse($data['scope']['global']);
        self::assertSame(['/api'], $data['scope']['pathPrefixes']);
        self::assertSame([7], $data['scope']['siteIds']);
    }

    public function testGetMaintenanceStatusNullScopeReturnsGlobal(): void
    {
        $activationContext = $this->makeActivationContextWithScope(null);

        $config = new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: null,
            publicStatusEnabled: true,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: 60,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: false,
            mailOnMaintenanceEnd: false,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            mailPreAnnounceTemplate: null,
            mailMaintenanceStartTemplate: null,
            mailMaintenanceEndTemplate: null,
            notificationWebhooks: [],
        );

        $scheduleStorage = $this->createStub(ScheduleStorage::class);
        $scheduleStorage->method('findAll')->willReturn([]);

        $helper = $this->createStub(MaintenanceModeHelperInterface::class);

        $controller = new class(
            $helper,
            $activationContext,
            $scheduleStorage,
            $config,
            new OverlapDetector(),
            $this->createStub(ScheduleHistoryRepositoryInterface::class),
            $this->createStub(SkipStorage::class),
            $this->createStub(QueuedWindowStorageInterface::class),
            new CompiledRulesProvider([]),
            new class extends PreAnnounceStorage {
                public function load(): ?PreAnnounceData { return null; }
            },
        ) extends ScheduleApiController {
            #[\Override]
            protected function isAllowedToManage(): bool { return true; }

            #[\Override]
            protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): \Symfony\Component\HttpFoundation\JsonResponse
            {
                return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status, $headers);
            }

            #[\Override]
            protected function getPimcoreUser(bool $proxyUser = false): \Pimcore\Security\User\User|\Pimcore\Model\User|null
            {
                return null;
            }
        };

        $request = Request::create('/maintenance-status', 'GET');
        $response = $controller->publicStatus($request);
        $data = \json_decode($response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('scope', $data);
        self::assertTrue($data['scope']['global']);
        self::assertSame([], $data['scope']['pathPrefixes']);
        self::assertSame([], $data['scope']['siteIds']);
    }

    public function testPostScheduleWithScopeStoresScope(): void
    {
        $capturedWindow = null;

        $scheduleStorage = $this->createMock(ScheduleStorage::class);
        $scheduleStorage->method('findAll')->willReturn([]);
        $scheduleStorage->expects($this->once())
            ->method('add')
            ->willReturnCallback(function (ScheduleWindow $w) use (&$capturedWindow): void {
                $capturedWindow = $w;
            });

        $controller = $this->makeController(userAllowed: true, scheduleStorage: $scheduleStorage);

        $body = \json_encode([
            'type'         => 'one-time',
            'from'         => '2026-06-10T10:00:00Z',
            'to'           => '2026-06-10T11:00:00Z',
            'pathPrefixes' => ['/shop', '/checkout'],
            'siteIds'      => [1, 2],
        ]);
        $request = Request::create('/admin/advanced-maintenance-mode/schedules', 'POST', [], [], [], [], $body);
        $response = $controller->createSchedule($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($capturedWindow);
        self::assertNotNull($capturedWindow->scope);
        self::assertFalse($capturedWindow->scope->isGlobal());
        self::assertSame(['/shop', '/checkout'], $capturedWindow->scope->pathPrefixes);
        self::assertSame([1, 2], $capturedWindow->scope->siteIds);
    }
}
