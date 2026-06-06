<?php
declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\PreAnnounceCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

final class PreAnnounceCommandTest extends TestCase
{
    private function makeStorage(): PreAnnounceStorage
    {
        return new class extends PreAnnounceStorage {
            public ?PreAnnounceData $saved = null;
            public function save(PreAnnounceData $d): void { $this->saved = $d; }
            public function load(): ?PreAnnounceData { return $this->saved; }
            public function clear(): void { $this->saved = null; }
        };
    }

    private function makeCommand(PreAnnounceStorage $storage, ?MaintenanceMailNotifier $mail = null, ?MaintenanceWebhookNotifier $webhook = null): PreAnnounceCommand
    {
        return new PreAnnounceCommand(
            $storage,
            $mail ?? $this->createStub(MaintenanceMailNotifier::class),
            $webhook ?? $this->createStub(MaintenanceWebhookNotifier::class),
        );
    }

    public function testSetsPreAnnouncement(): void
    {
        $storage = $this->makeStorage();
        $tester = new CommandTester($this->makeCommand($storage));

        $future = (new \DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s');
        $tester->execute(['--at' => $future, '--reason' => 'DB migration', '--timezone' => 'UTC']);

        $tester->assertCommandIsSuccessful();
        self::assertNotNull($storage->saved);
        self::assertSame('DB migration', $storage->saved->reason);
        self::assertStringContainsString('Pre-announcement set', $tester->getDisplay());
    }

    public function testNotifiersCalledOnSuccess(): void
    {
        $storage = $this->makeStorage();

        $mail = $this->createMock(MaintenanceMailNotifier::class);
        $mail->expects($this->once())->method('notifyPreAnnounce');

        $webhook = $this->createMock(MaintenanceWebhookNotifier::class);
        $webhook->expects($this->once())->method('notifyPreAnnounce');

        $future = (new \DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s');
        $tester = new CommandTester($this->makeCommand($storage, $mail, $webhook));
        $tester->execute(['--at' => $future, '--timezone' => 'UTC']);

        $tester->assertCommandIsSuccessful();
    }

    public function testFailsWhenAtIsInPast(): void
    {
        $storage = $this->makeStorage();
        $tester = new CommandTester($this->makeCommand($storage));

        $past = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        $result = $tester->execute(['--at' => $past, '--timezone' => 'UTC']);

        self::assertSame(1, $result);
        self::assertStringContainsString('must be in the future', $tester->getDisplay());
    }

    public function testFailsWithInvalidTimezone(): void
    {
        $storage = $this->makeStorage();
        $tester = new CommandTester($this->makeCommand($storage));

        $future = (new \DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s');
        $result = $tester->execute(['--at' => $future, '--timezone' => 'Not/ATimezone']);

        self::assertSame(1, $result);
        self::assertStringContainsString('Invalid timezone', $tester->getDisplay());
    }

    public function testFailsWithNegativeAnnounceBefore(): void
    {
        $storage = $this->makeStorage();
        $tester = new CommandTester($this->makeCommand($storage));

        $future = (new \DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s');
        $result = $tester->execute(['--at' => $future, '--timezone' => 'UTC', '--announce-before' => '-5']);

        self::assertSame(1, $result);
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }
}
