<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\EnableCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;

final class EnableCommandTest extends TestCase
{
    private function fakeContext(): array
    {
        $storage = new class implements ContextStorageInterface {
            public ?string $reason = null;
            public ?int $retry = null;
            public bool $cleared = false;
            public function load(): array { return ['reason' => $this->reason, 'retry_after' => $this->retry]; }
            public function save(?string $reason, ?int $retryAfter): void { $this->reason = $reason; $this->retry = $retryAfter; }
            public function clear(): void { $this->cleared = true; $this->reason = null; $this->retry = null; }
        };
        return [new ActivationContext($storage), $storage];
    }

    public function testActivatesWithDefaults(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('activate')->with('command-line-dummy-session-id');

        [$context, $storage] = $this->fakeContext();
        $tester = new CommandTester(new EnableCommand($helper, $context));

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
        $tester = new CommandTester(new EnableCommand($helper, $context));

        $tester->execute([
            '--reason' => 'DB migration v3.5',
            '--retry-after' => '600',
            '--session-id' => 'custom-session-id',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('DB migration v3.5', $storage->reason);
        self::assertSame(600, $storage->retry);
    }
}
