<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener\NativeMaintenanceCommandNoticeListener;

final class NativeMaintenanceCommandNoticeListenerTest extends TestCase
{
    private function makeEvent(string $name): ConsoleCommandEvent
    {
        $command = new class extends Command {};
        $command->setName($name);
        $command->setDefinition(new InputDefinition());

        return new ConsoleCommandEvent(
            $command,
            new ArrayInput([], $command->getDefinition()),
            new BufferedOutput(),
        );
    }

    public function testPrintsNoticeForPimcoreMaintenanceMode(): void
    {
        $event = $this->makeEvent('pimcore:maintenance-mode');

        (new NativeMaintenanceCommandNoticeListener())->onConsoleCommand($event);

        $output = $event->getOutput();
        \assert($output instanceof BufferedOutput);
        $text = $output->fetch();
        self::assertStringContainsString('Advanced features', $text);
        self::assertStringContainsString('pimcore:advanced-maintenance:enable', $text);
    }

    public function testSilentForOtherCommands(): void
    {
        $event = $this->makeEvent('messenger:consume');

        (new NativeMaintenanceCommandNoticeListener())->onConsoleCommand($event);

        $output = $event->getOutput();
        \assert($output instanceof BufferedOutput);
        self::assertSame('', $output->fetch());
    }
}
