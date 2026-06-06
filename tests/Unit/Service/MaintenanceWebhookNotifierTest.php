<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;

final class MaintenanceWebhookNotifierTest extends TestCase
{
    private function makeConfig(array $webhooks = ['https://hooks.example.com/maintenance']): BundleConfiguration
    {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
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
            notificationWebhooks: $webhooks,
        );
    }

    public function testSilentWhenNoWebhookUrlsAndNoWarning(): void
    {
        // symfony/http-client is available in this environment, so we verify
        // that having no webhooks configured never triggers a warning
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $notifier = new MaintenanceWebhookNotifier($this->makeConfig([]), $logger);
        $notifier->notifyMaintenanceEnd('disable');
    }

    public function testSilentWhenNoWebhooksConfigured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $notifier = new MaintenanceWebhookNotifier($this->makeConfig([]), $logger);
        $notifier->notifyMaintenanceStart(null, null, null);
    }

    public function testDebugLogWhenNoWebhooks(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('debug');

        $notifier = new MaintenanceWebhookNotifier($this->makeConfig([]), $logger);
        $notifier->notifyMaintenanceStart('reason', 300, 'cli');
    }

    public function testPreAnnounceDoesNotThrow(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $notifier = new MaintenanceWebhookNotifier($this->makeConfig(), $logger);

        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', 'test', null);
        $notifier->notifyPreAnnounce($data);

        // If we get here without exception, the test passes
        self::assertTrue(true);
    }
}
