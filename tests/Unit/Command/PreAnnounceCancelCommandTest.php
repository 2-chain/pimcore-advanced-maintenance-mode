<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\PreAnnounceCancelCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;
use DateTimeImmutable;

final class PreAnnounceCancelCommandTest extends TestCase
{
    private function makeStorage(?PreAnnounceData $data): PreAnnounceStorage
    {
        return new class ($data) extends PreAnnounceStorage {
            public bool $cleared = false;
            public function __construct(private ?PreAnnounceData $d) {}
            public function load(): ?PreAnnounceData
            {
                return $this->d;
            }
            public function save(PreAnnounceData $d): void {}
            public function clear(): void
            {
                $this->cleared = true;
                $this->d = null;
            }
        };
    }

    public function testCancelsExistingAnnouncement(): void
    {
        $data = new PreAnnounceData(new DateTimeImmutable('+1 hour'), 'UTC', 'test', null);
        $storage = $this->makeStorage($data);
        $tester = new CommandTester(new PreAnnounceCancelCommand($storage));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($storage->cleared);
        self::assertStringContainsString('cancelled', $tester->getDisplay());
    }

    public function testFailsWhenNothingSet(): void
    {
        $storage = $this->makeStorage(null);
        $tester = new CommandTester(new PreAnnounceCancelCommand($storage));

        $result = $tester->execute([]);

        self::assertSame(1, $result);
        self::assertStringContainsString('No pre-announcement', $tester->getDisplay());
    }
}
