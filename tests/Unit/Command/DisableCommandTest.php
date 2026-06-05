<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\DisableCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;

final class DisableCommandTest extends TestCase
{
    private function fakeContext(): array
    {
        $storage = new class implements ContextStorageInterface {
            public bool $cleared = false;
            public function load(): array
            {
                return ['reason' => null, 'retry_after' => null];
            }
            public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
            ): void {}
            public function clear(): void
            {
                $this->cleared = true;
            }
        };
        return [new ActivationContext($storage), $storage];
    }

    private function makeConfig(bool $mailOnEnd = false, array $webhooks = []): BundleConfiguration
    {
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
            mailOnPreAnnounce: false,
            mailOnMaintenanceStart: false,
            mailOnMaintenanceEnd: $mailOnEnd,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            notificationWebhooks: $webhooks,
        );
    }

    private function makeDisableCommand(
        MaintenanceModeHelperInterface $helper,
        ActivationContext $context,
        ?MaintenanceMailNotifier $mailNotifier = null,
        ?MaintenanceWebhookNotifier $webhookNotifier = null,
        ?BundleConfiguration $config = null,
    ): DisableCommand {
        return new DisableCommand(
            $helper,
            $context,
            $mailNotifier ?? $this->createStub(MaintenanceMailNotifier::class),
            $webhookNotifier ?? $this->createStub(MaintenanceWebhookNotifier::class),
            $config ?? $this->makeConfig(),
        );
    }

    public function testDeactivatesAndClearsContext(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('deactivate');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeDisableCommand($helper, $context));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($storage->cleared);
        self::assertStringContainsString('Maintenance mode disabled', $tester->getDisplay());
    }

    public function testFiresMailNotifierWhenConfigured(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        [$context,] = $this->fakeContext();

        $mailNotifier = $this->createMock(MaintenanceMailNotifier::class);
        $mailNotifier->expects(self::once())->method('notifyMaintenanceEnd');

        $tester = new CommandTester($this->makeDisableCommand(
            $helper, $context,
            config: $this->makeConfig(mailOnEnd: true),
            mailNotifier: $mailNotifier,
        ));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
