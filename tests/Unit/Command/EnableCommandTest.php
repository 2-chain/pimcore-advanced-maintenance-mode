<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\EnableCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

final class EnableCommandTest extends TestCase
{
    private function fakeContext(): array
    {
        $storage = new class implements ContextStorageInterface {
            public ?string $reason = null;
            public ?int $retry = null;
            public bool $cleared = false;
            public function load(): array
            {
                return ['reason' => $this->reason, 'retry_after' => $this->retry];
            }
            public function save(
                ?string $reason,
                ?int $retryAfter,
                ?string $activatedByScheduleWindowId = null,
                ?string $expectedEndAt = null,
                bool $activatedByHealthCheckFailure = false,
                ?int $activatedByHistoryRecordId = null,
            ): void {
                $this->reason = $reason;
                $this->retry = $retryAfter;
            }
            public function clear(): void
            {
                $this->cleared = true;
                $this->reason = null;
                $this->retry = null;
            }
        };
        return [new ActivationContext($storage), $storage];
    }

    private function makeConfig(bool $mailOnStart = false, array $webhooks = []): BundleConfiguration
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
            mailOnMaintenanceStart: $mailOnStart,
            mailOnMaintenanceEnd: false,
            mailRecipients: [],
            mailOnPreAnnounceRecipients: [],
            mailOnMaintenanceStartRecipients: [],
            mailOnMaintenanceEndRecipients: [],
            mailTemplate: null,
            notificationWebhooks: $webhooks,
        );
    }

    private function makeEnableCommand(
        MaintenanceModeHelperInterface $helper,
        ActivationContext $context,
        ?PreAnnounceStorage $preAnnounceStorage = null,
        ?MaintenanceMailNotifier $mailNotifier = null,
        ?MaintenanceWebhookNotifier $webhookNotifier = null,
        ?BundleConfiguration $config = null,
    ): EnableCommand {
        return new EnableCommand(
            $helper,
            $context,
            $preAnnounceStorage ?? $this->createStub(PreAnnounceStorage::class),
            $mailNotifier ?? $this->createStub(MaintenanceMailNotifier::class),
            $webhookNotifier ?? $this->createStub(MaintenanceWebhookNotifier::class),
            $config ?? $this->makeConfig(),
        );
    }

    public function testActivatesWithDefaults(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('activate')->with('command-line-dummy-session-id');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($storage->reason);
        self::assertNull($storage->retry);
        self::assertStringContainsString('Maintenance mode enabled', $tester->getDisplay());
    }

    public function testActivatesWithReasonAndRetryAfter(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('activate')->with('custom-session-id');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester($this->makeEnableCommand($helper, $context));

        $tester->execute([
            '--reason' => 'DB migration v3.5',
            '--retry-after' => '600',
            '--session-id' => 'custom-session-id',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('DB migration v3.5', $storage->reason);
        self::assertSame(600, $storage->retry);
    }

    public function testClearsPreAnnounceStorageOnEnable(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        [$context,] = $this->fakeContext();

        $preAnnounceStorage = new class extends PreAnnounceStorage {
            public bool $cleared = false;
            public function clear(): void { $this->cleared = true; }
        };

        $tester = new CommandTester($this->makeEnableCommand($helper, $context, $preAnnounceStorage));
        $tester->execute([]);

        self::assertTrue($preAnnounceStorage->cleared);
    }

    public function testFiresMailNotifierWhenConfigured(): void
    {
        $helper = $this->createStub(MaintenanceModeHelperInterface::class);
        [$context,] = $this->fakeContext();

        $mailNotifier = $this->createMock(MaintenanceMailNotifier::class);
        $mailNotifier->expects(self::once())->method('notifyMaintenanceStart');

        $tester = new CommandTester($this->makeEnableCommand(
            $helper, $context,
            config: $this->makeConfig(mailOnStart: true),
            mailNotifier: $mailNotifier,
        ));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
