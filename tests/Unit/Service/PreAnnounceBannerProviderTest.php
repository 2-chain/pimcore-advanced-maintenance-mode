<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceBannerProvider;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

final class PreAnnounceBannerProviderTest extends TestCase
{
    private function makeConfig(int $thresholdMinutes = 60): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: $thresholdMinutes,
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
            notificationWebhooks: [],
        );
    }

    private function makePreAnnounceStorage(?PreAnnounceData $data): PreAnnounceStorage
    {
        return new class($data) extends PreAnnounceStorage {
            public function __construct(private readonly ?PreAnnounceData $data) {}
            public function load(): ?PreAnnounceData { return $this->data; }
            public function save(PreAnnounceData $d): void {}
            public function clear(): void {}
        };
    }

    private function makeScheduleStorage(array $windows): ScheduleStorage
    {
        return new class($windows) extends ScheduleStorage {
            public function __construct(private readonly array $windows) {}
            public function findAll(): array { return $this->windows; }
        };
    }

    private function makeHelper(bool $active): MaintenanceModeHelperInterface
    {
        $h = $this->createStub(MaintenanceModeHelperInterface::class);
        $h->method('isActive')->willReturn($active);
        return $h;
    }

    public function testReturnsNullWhenMaintenanceIsActive(): void
    {
        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(true),
            storage: $this->makePreAnnounceStorage(null),
            config: $this->makeConfig(),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        self::assertNull($provider->provide());
    }

    public function testReturnsNullWhenNoDataAndNoWindows(): void
    {
        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage(null),
            config: $this->makeConfig(),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        self::assertNull($provider->provide());
    }

    public function testReturnsManualDataWithinThreshold(): void
    {
        $at = new \DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC'));
        $data = new PreAnnounceData(at: $at, timezone: 'UTC', reason: 'test', announceBeforeMinutes: null);

        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage($data),
            config: $this->makeConfig(60),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        $result = $provider->provide();
        self::assertNotNull($result);
        self::assertSame($at->getTimestamp(), $result->at->getTimestamp());
    }

    public function testReturnsNullWhenManualDataInPast(): void
    {
        $at = new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC'));
        $data = new PreAnnounceData(at: $at, timezone: 'UTC', reason: null, announceBeforeMinutes: null);

        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage($data),
            config: $this->makeConfig(60),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        self::assertNull($provider->provide());
    }

    public function testManualDataBeyondThresholdNotReturned(): void
    {
        $at = new \DateTimeImmutable('+120 minutes', new \DateTimeZone('UTC'));
        $data = new PreAnnounceData(at: $at, timezone: 'UTC', reason: null, announceBeforeMinutes: null);

        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage($data),
            config: $this->makeConfig(60),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        self::assertNull($provider->provide());
    }

    public function testAnnounceBeforeMinutesOnDataOverridesConfig(): void
    {
        $at = new \DateTimeImmutable('+70 minutes', new \DateTimeZone('UTC'));
        $data = new PreAnnounceData(at: $at, timezone: 'UTC', reason: null, announceBeforeMinutes: 90);

        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage($data),
            config: $this->makeConfig(60),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        self::assertNotNull($provider->provide());
    }

    public function testScheduleWindowWithinAnnounceWindowReturnsData(): void
    {
        $from = new \DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC'));
        $to   = new \DateTimeImmutable('+90 minutes', new \DateTimeZone('UTC'));
        $window = new ScheduleWindow(
            id: 'w1', timezone: 'UTC', reason: 'deploy',
            from: $from, to: $to,
            cronExpression: null, durationMinutes: null,
            announceBeforeMinutes: 60,
        );

        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage(null),
            config: $this->makeConfig(60),
            scheduleStorage: $this->makeScheduleStorage([$window]),
        );

        $result = $provider->provide();
        self::assertNotNull($result);
        self::assertSame($from->getTimestamp(), $result->at->getTimestamp());
        self::assertSame('deploy', $result->reason);
    }

    public function testScheduleWindowBeyondAnnounceWindowReturnsNull(): void
    {
        $from = new \DateTimeImmutable('+120 minutes', new \DateTimeZone('UTC'));
        $to   = new \DateTimeImmutable('+180 minutes', new \DateTimeZone('UTC'));
        $window = new ScheduleWindow(
            id: 'w2', timezone: 'UTC', reason: null,
            from: $from, to: $to,
            cronExpression: null, durationMinutes: null,
            announceBeforeMinutes: 60,
        );

        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage(null),
            config: $this->makeConfig(60),
            scheduleStorage: $this->makeScheduleStorage([$window]),
        );

        self::assertNull($provider->provide());
    }

    public function testWasRenderedDefaultsFalse(): void
    {
        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage(null),
            config: $this->makeConfig(),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        self::assertFalse($provider->wasRendered());
    }

    public function testMarkRenderedFlipsFlag(): void
    {
        $provider = new PreAnnounceBannerProvider(
            helper: $this->makeHelper(false),
            storage: $this->makePreAnnounceStorage(null),
            config: $this->makeConfig(),
            scheduleStorage: $this->makeScheduleStorage([]),
        );

        $provider->markRendered();
        self::assertTrue($provider->wasRendered());
    }
}
