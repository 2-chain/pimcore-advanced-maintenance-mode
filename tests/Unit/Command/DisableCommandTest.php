<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\DisableCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;

final class DisableCommandTest extends TestCase
{
    public function testDeactivatesAndClearsContext(): void
    {
        $helper = $this->createMock(MaintenanceModeHelperInterface::class);
        $helper->expects(self::once())->method('deactivate');

        $storage = new class implements ContextStorageInterface {
            public bool $cleared = false;
            public function load(): array { return ['reason' => null, 'retry_after' => null]; }
            public function save(?string $reason, ?int $retryAfter): void {}
            public function clear(): void { $this->cleared = true; }
        };

        $tester = new CommandTester(new DisableCommand($helper, new ActivationContext($storage)));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertTrue($storage->cleared);
        self::assertStringContainsString('Maintenance mode disabled', $tester->getDisplay());
    }
}
