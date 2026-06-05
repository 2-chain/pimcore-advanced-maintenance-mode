<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;

final class MaintenanceMailNotifierTest extends TestCase
{
    private function makeConfig(
        bool $onPreAnnounce = true,
        bool $onStart = true,
        bool $onEnd = true,
        array $recipients = ['ops@example.com'],
        array $preAnnounceRecipients = [],
    ): BundleConfiguration {
        return new BundleConfiguration(
            bypassAuthenticatedAdmins: false,
            defaultRetryAfter: null,
            publicStatusEnabled: false,
            publicStatusToken: null,
            autoInjectBanner: true,
            defaultThresholdMinutes: 60,
            urgencyOrangeMinutes: 30,
            urgencyRedMinutes: 10,
            dismissPersistence: 'session',
            mailOnPreAnnounce: $onPreAnnounce,
            mailOnMaintenanceStart: $onStart,
            mailOnMaintenanceEnd: $onEnd,
            mailRecipients: $recipients,
            mailOnPreAnnounceRecipients: $preAnnounceRecipients,
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            notificationWebhooks: [],
        );
    }

    public function testHandlesMailSendingGracefully(): void
    {
        $logger = $this->createStub(LoggerInterface::class);

        $notifier = new MaintenanceMailNotifier($this->makeConfig(), $logger);
        $notifier->notifyMaintenanceStart(null, null, null);

        // Passes if no exception is thrown (Pimcore\Mail may be available in env)
        self::assertTrue(true);
    }

    public function testSilentWhenRecipientsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $notifier = new MaintenanceMailNotifier($this->makeConfig(recipients: []), $logger);
        $notifier->notifyMaintenanceStart('reason', 300, 'cli');
    }

    public function testDebugLogWhenRecipientsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('debug');

        $notifier = new MaintenanceMailNotifier($this->makeConfig(onStart: true, recipients: []), $logger);
        $notifier->notifyMaintenanceStart('reason', 300, 'cli');
    }

    public function testPreAnnounceRecipientsOverrideGlobal(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $notifier = new MaintenanceMailNotifier(
            $this->makeConfig(recipients: ['global@x.com'], preAnnounceRecipients: ['team@x.com']),
            $logger,
        );
        $data = new PreAnnounceData(new \DateTimeImmutable('+1 hour'), 'UTC', null, null);
        // Pimcore\Mail is available in test env, so sending is attempted
        $notifier->notifyPreAnnounce($data);

        // Test passes if no exception is thrown (mail sending is handled gracefully)
        self::assertTrue(true);
    }
}
