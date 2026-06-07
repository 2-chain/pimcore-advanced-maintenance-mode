<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\NullLogger;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ScheduleEnforcementTask;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Repository\Fixtures\InMemoryScheduleHistoryRepository;

final class ScheduleEnforcementTaskTest extends TestCase
{
    private function utc(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    private function activeOneTimeWindow(string $id): ScheduleWindow
    {
        return new ScheduleWindow($id, 'UTC', 'deploy',
            $this->utc('2026-06-02T00:00:00Z'), $this->utc('2026-06-02T23:59:59Z'),
            null, null);
    }

    private function expiredOneTimeWindow(string $id): ScheduleWindow
    {
        return new ScheduleWindow($id, 'UTC', 'old',
            $this->utc('2026-06-01T00:00:00Z'), $this->utc('2026-06-01T01:00:00Z'),
            null, null);
    }

    private function makeConfig(?array $defaultScopeData = null): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            defaultTtl: null,
            expiryWarningThreshold: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: false,
            defaultThresholdMinutes: null,
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
            defaultScopeData: $defaultScopeData,
        );
    }

    private function makeTask(
        MaintenanceModeHelperInterface $helper,
        ActivationContext $ctx,
        ScheduleStorage $storage,
        QueuedWindowStorageInterface $queue,
        ?BundleConfiguration $config = null,
    ): ScheduleEnforcementTask {
        return new ScheduleEnforcementTask($helper, $ctx, $storage, $queue, new NullLogger(), new InMemoryScheduleHistoryRepository(), new InMemorySkipStorage(), $config ?? $this->makeConfig());
    }

    /** Case A: window active, mode OFF → activate */
    public function testCaseAActivatesWhenWindowActiveAndModeOff(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);
        $helper->expects(self::once())->method('activate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$this->activeOneTimeWindow('w1')]);

        $ctx   = new ActivationContext(new InMemoryContextStorageForTask());
        $queue = new InMemoryQueuedWindowStorage();

        $task = $this->makeTask($helper, $ctx, $storage, $queue);
        $task->executeAtTime($this->utc('2026-06-02T12:00:00Z'));

        self::assertSame('w1', $ctx->getActivatedByScheduleWindowId());
    }

    /** Case B: no active window, mode ON, activated by schedule → deactivate */
    public function testCaseBDeactivatesWhenWindowEndedAndModeOn(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $helper->expects(self::once())->method('deactivate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([]);

        $innerStorage = new InMemoryContextStorageForTask();
        $innerStorage->windowId = 'w1'; // context says w1 owns maintenance
        $ctx   = new ActivationContext($innerStorage);
        $queue = new InMemoryQueuedWindowStorage();

        $task = $this->makeTask($helper, $ctx, $storage, $queue);
        $task->executeAtTime($this->utc('2026-06-02T05:00:00Z'));

        self::assertNull($ctx->getActivatedByScheduleWindowId());
    }

    /** Case C: window active, mode ON, activated_by_schedule_window_id = null → queue */
    public function testCaseCQueuesWindowDuringManualMaintenance(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $helper->expects(self::never())->method('deactivate');
        $helper->expects(self::never())->method('activate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$this->activeOneTimeWindow('w1')]);

        $ctx   = new ActivationContext(new InMemoryContextStorageForTask()); // windowId = null
        $queue = new InMemoryQueuedWindowStorage();

        $task = $this->makeTask($helper, $ctx, $storage, $queue);
        $task->executeAtTime($this->utc('2026-06-02T12:00:00Z'));

        self::assertSame(['w1'], $queue->all());
    }

    /** Case D: nothing active, mode OFF → do nothing */
    public function testCaseDDoesNothingWhenBothOff(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);
        $helper->expects(self::never())->method('activate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([]);

        $ctx   = new ActivationContext(new InMemoryContextStorageForTask());
        $queue = new InMemoryQueuedWindowStorage();

        $task = $this->makeTask($helper, $ctx, $storage, $queue);
        $task->executeAtTime($this->utc('2026-06-02T05:00:00Z'));
    }

    /** GC: expired one-time windows are removed from storage */
    public function testGcRemovesExpiredWindows(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);

        $storage = $this->createMock(ScheduleStorage::class);
        $expired = $this->expiredOneTimeWindow('expired');
        $storage->method('findAll')->willReturn([$expired]);
        $storage->expects(self::once())->method('replaceAll')->with([]);

        $ctx   = new ActivationContext(new InMemoryContextStorageForTask());
        $queue = new InMemoryQueuedWindowStorage();

        $task = $this->makeTask($helper, $ctx, $storage, $queue);
        $task->executeAtTime($this->utc('2026-06-02T05:00:00Z'));
    }

    public function testCaseAInsertsHistoryRecord(): void
    {
        $now    = new \DateTimeImmutable('2026-06-02T10:00:00Z', new \DateTimeZone('UTC'));
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);
        $helper->expects(self::once())->method('activate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$this->activeOneTimeWindow('win-1')]);

        $innerStorage = new InMemoryContextStorageForTask();
        $ctx          = new ActivationContext($innerStorage);
        $queue        = new InMemoryQueuedWindowStorage();
        $repo         = new InMemoryScheduleHistoryRepository();
        $skip         = new InMemorySkipStorage();

        $task = new ScheduleEnforcementTask($helper, $ctx, $storage, $queue, new NullLogger(), $repo, $skip, $this->makeConfig());
        $task->executeAtTime($now);

        self::assertSame(1, $repo->insertCount());
        self::assertSame('win-1', $repo->lastInsertedWindowId());
        self::assertSame(1, $ctx->getActivatedByHistoryRecordId());
    }

    public function testCaseASkipsWindowWhenSkipStorageSaysSkip(): void
    {
        $now    = new \DateTimeImmutable('2026-06-02T12:00:00Z', new \DateTimeZone('UTC'));
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);
        $helper->expects(self::never())->method('activate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$this->activeOneTimeWindow('win-skip')]);

        $ctx    = new ActivationContext(new InMemoryContextStorageForTask());
        $queue  = new InMemoryQueuedWindowStorage();
        $repo   = new InMemoryScheduleHistoryRepository();
        $skip   = new InMemorySkipStorage();
        $skip->skip('win-skip', $now->modify('+10 minutes'));

        $task = new ScheduleEnforcementTask($helper, $ctx, $storage, $queue, new NullLogger(), $repo, $skip, $this->makeConfig());
        $task->executeAtTime($now);

        self::assertSame(0, $repo->insertCount());
    }

    public function testCaseBUpdatesHistoryRecord(): void
    {
        $now    = new \DateTimeImmutable('2026-06-02T11:00:00Z', new \DateTimeZone('UTC'));
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(true);
        $helper->expects(self::once())->method('deactivate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([]);

        $innerStorage         = new InMemoryContextStorageForTask();
        $innerStorage->windowId = 'win-2';
        $ctx                  = new ActivationContext($innerStorage);
        $ctx->setActivatedByHistoryRecordId(5);

        $repo = new InMemoryScheduleHistoryRepository();
        $repo->seedRecord(5, 'win-2');
        $skip  = new InMemorySkipStorage();
        $queue = new InMemoryQueuedWindowStorage();

        $task = new ScheduleEnforcementTask($helper, $ctx, $storage, $queue, new NullLogger(), $repo, $skip, $this->makeConfig());
        $task->executeAtTime($now);

        self::assertTrue($repo->wasEndUpdated(5));
    }

    public function testCaseAScopedWindowStoresScopeInContext(): void
    {
        $now    = new \DateTimeImmutable('2026-06-02T12:00:00Z', new \DateTimeZone('UTC'));
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);
        $helper->expects(self::once())->method('activate');

        $scope  = new MaintenanceScope(['/shop'], [2]);
        $window = new ScheduleWindow(
            'w-scoped', 'UTC', 'deploy',
            $this->utc('2026-06-02T00:00:00Z'), $this->utc('2026-06-02T23:59:59Z'),
            null, null, 0, 0, '', $scope,
        );

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$window]);

        $innerStorage = new InMemoryContextStorageForTask();
        $ctx          = new ActivationContext($innerStorage);
        $queue        = new InMemoryQueuedWindowStorage();

        $task = $this->makeTask($helper, $ctx, $storage, $queue);
        $task->executeAtTime($now);

        self::assertSame(['/shop'], $innerStorage->scopeRaw['path_prefixes'] ?? null);
    }

    public function testCaseANullWindowScopeUsesYamlDefault(): void
    {
        $now    = new \DateTimeImmutable('2026-06-02T12:00:00Z', new \DateTimeZone('UTC'));
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);
        $helper->expects(self::once())->method('activate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$this->activeOneTimeWindow('w-default')]);

        $innerStorage = new InMemoryContextStorageForTask();
        $ctx          = new ActivationContext($innerStorage);
        $queue        = new InMemoryQueuedWindowStorage();
        $config       = $this->makeConfig(['path_prefixes' => ['/checkout'], 'site_ids' => []]);

        $task = $this->makeTask($helper, $ctx, $storage, $queue, $config);
        $task->executeAtTime($now);

        self::assertSame(['/checkout'], $innerStorage->scopeRaw['path_prefixes'] ?? null);
    }

    public function testCaseANullWindowScopeNoYamlDefaultStoresNullScope(): void
    {
        $now    = new \DateTimeImmutable('2026-06-02T12:00:00Z', new \DateTimeZone('UTC'));
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->method('isActive')->willReturn(false);
        $helper->expects(self::once())->method('activate');

        $storage = $this->createStub(ScheduleStorage::class);
        $storage->method('findAll')->willReturn([$this->activeOneTimeWindow('w-no-scope')]);

        $innerStorage = new InMemoryContextStorageForTask();
        $ctx          = new ActivationContext($innerStorage);
        $queue        = new InMemoryQueuedWindowStorage();

        $task = $this->makeTask($helper, $ctx, $storage, $queue, $this->makeConfig(null));
        $task->executeAtTime($now);

        self::assertNull($innerStorage->scopeRaw);
    }
}

// Inline helper — real storage so ActivationContext reads/writes work in tests
final class InMemoryContextStorageForTask implements \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\ContextStorageInterface
{
    public ?string $windowId = null;
    public ?int $historyRecordId = null;
    public ?array $scopeRaw = null;

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
            'scope'                              => $this->scopeRaw,
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
        $this->historyRecordId = $activatedByHistoryRecordId;
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
        $this->historyRecordId = null;
        $this->scopeRaw = null;
    }

    #[\Override]
    public function saveScope(?array $scopeRaw): void
    {
        $this->scopeRaw = $scopeRaw;
    }
}

// Inline fake for SkipStorage — overrides loadMap/saveMap to use in-memory array
final class InMemorySkipStorage extends \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\SkipStorage
{
    private array $store = [];

    #[\Override]
    protected function loadMap(): array { return $this->store; }

    #[\Override]
    protected function saveMap(array $map): void { $this->store = $map; }
}

// Inline fake for QueuedWindowStorage (final class — cannot be mocked or extended)
final class InMemoryQueuedWindowStorage implements \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\Interfaces\QueuedWindowStorageInterface
{
    /** @var string[] */
    private array $ids = [];

    #[\Override]
    public function all(): array
    {
        return $this->ids;
    }

    #[\Override]
    public function enqueue(string $windowId): void
    {
        if (!\in_array($windowId, $this->ids, true)) {
            $this->ids[] = $windowId;
        }
    }

    #[\Override]
    public function dequeueEarliest(): ?string
    {
        if ($this->ids === []) {
            return null;
        }
        return \array_shift($this->ids);
    }

    #[\Override]
    public function remove(string $windowId): void
    {
        $this->ids = \array_values(\array_filter($this->ids, static fn(string $id) => $id !== $windowId));
    }

    #[\Override]
    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    #[\Override]
    public function clear(): void
    {
        $this->ids = [];
    }
}
