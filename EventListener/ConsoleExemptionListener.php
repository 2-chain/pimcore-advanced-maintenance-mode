<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener;

use Pimcore\Tool\MaintenanceModeHelperInterface;
use RuntimeException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ExemptionEvaluator;

final class ConsoleExemptionListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ExemptionEvaluator $evaluator,
        private readonly ActivationContext $context,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => ['onConsoleCommand', 100]];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $input = $event->getInput();

        if ($input->hasOption('ignore-maintenance-mode') && $input->getOption('ignore-maintenance-mode')) {
            return;
        }

        if (!$this->helper->isActive()) {
            return;
        }

        $command = $event->getCommand();
        if ($command === null) {
            return;
        }

        $name = $command->getName();
        if ($name === null || $name === '') {
            return;
        }

        $match = $this->evaluator->evaluateCommand($name);
        $reason = $this->context->getReason();

        if ($match === null) {
            // No exemption applies. Pimcore's own gate (priority 0) would throw
            // a generic "In maintenance mode" exception. We throw a more useful
            // one here that includes the activation reason when set, before
            // Pimcore's listener runs.
            throw new RuntimeException($this->buildBlockedMessage($reason));
        }

        if ($input->hasOption('ignore-maintenance-mode')) {
            $input->setOption('ignore-maintenance-mode', true);
        }

        $out = $event->getOutput();
        $out->writeln(\sprintf(
            '<comment>[maintenance bypass]</comment> Rule "%s" (%s) — command runs under exemption.',
            $match->ruleId,
            $match->source->value,
        ));

        if ($reason !== null) {
            $out->writeln(\sprintf('<comment>[maintenance]</comment> Reason: %s', $reason));
        }

        // TTL expiry line: only for manual activations with a TTL set
        if (
            $this->context->getActivatedByScheduleWindowId() === null
            && ($expiresAt = $this->context->getExpiresAt()) !== null
        ) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $secondsRemaining = $expiresAt->getTimestamp() - $now->getTimestamp();
            $minutesRemaining = (int) \ceil($secondsRemaining / 60);
            $out->writeln(\sprintf(
                '<comment>[maintenance]</comment> Expires in: %d min (at %s)',
                \max(0, $minutesRemaining),
                $expiresAt->format('Y-m-d H:i:s \U\T\C'),
            ));
        }
    }

    private function buildBlockedMessage(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return 'In maintenance mode — set --ignore-maintenance-mode to force execution.';
        }

        return \sprintf(
            'In maintenance mode (reason: %s) — set --ignore-maintenance-mode to force execution.',
            $reason,
        );
    }
}
