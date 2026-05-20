<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class NativeMaintenanceCommandNoticeListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => ['onConsoleCommand', 50]];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if ($command === null || $command->getName() !== 'pimcore:maintenance-mode') {
            return;
        }

        $out = $event->getOutput();
        $out->writeln('<info>[INFO] Advanced features (exemption rules, --reason, --retry-after) are only</info>');
        $out->writeln('<info>       available via `pimcore:advanced-maintenance:enable`.</info>');
        $out->writeln('<info>       This command will continue with default behavior.</info>');
    }
}
