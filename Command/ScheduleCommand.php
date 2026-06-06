<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\ScheduleWindow;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Detector\OverlapDetector;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:schedule',
    description: 'Schedule a maintenance window (one-time or recurring)',
)]
final class ScheduleCommand extends Command
{
    public function __construct(
        private readonly ScheduleStorage $storage,
        private readonly OverlapDetector $overlapDetector,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('from',     null, InputOption::VALUE_REQUIRED, 'Start datetime (ISO 8601 UTC) for one-time window')
            ->addOption('to',       null, InputOption::VALUE_REQUIRED, 'End datetime (ISO 8601 UTC) for one-time window')
            ->addOption('cron',     null, InputOption::VALUE_REQUIRED, 'Cron expression for recurring window')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Duration in minutes for recurring window')
            ->addOption('reason',   null, InputOption::VALUE_REQUIRED, 'Human-readable reason shown during maintenance')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'PHP timezone identifier', \ini_get('date.timezone') ?: 'UTC')
            ->addOption('announce-before', null, InputOption::VALUE_REQUIRED, 'Minutes before start to show pre-announce banner (0 = use config default)', 0)
            ->addOption('id',       null, InputOption::VALUE_REQUIRED, 'Window ID (auto-generated if omitted)')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Validate and show what would be scheduled without persisting')
            ->addOption('path-prefix', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict this window to URL path prefix (repeatable)')
            ->addOption('site-id',     null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict this window to Pimcore site ID (repeatable)');
    }

    #[Override]
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasOption('ignore-maintenance-mode')) {
            $input->setOption('ignore-maintenance-mode', true);
        }
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        $from     = $input->getOption('from');
        $to       = $input->getOption('to');
        $cron     = $input->getOption('cron');
        $duration = $input->getOption('duration');

        $hasOneTime   = ($from !== null && $to !== null);
        $hasRecurring = ($cron !== null && $duration !== null);

        if ($hasOneTime === $hasRecurring) {
            $io->error('Provide exactly one of (--from + --to) or (--cron + --duration).');
            return Command::FAILURE;
        }

        $timezone            = (string) ($input->getOption('timezone') ?? 'UTC');
        $reason              = $input->getOption('reason');
        $reason              = \is_string($reason) && $reason !== '' ? $reason : null;
        $id                  = $input->getOption('id') ?? \bin2hex(\random_bytes(8));
        $announceBeforeRaw   = $input->getOption('announce-before');
        $announceBeforeMinutes = \is_numeric($announceBeforeRaw) ? (int) $announceBeforeRaw : 0;

        // Scope resolution
        $pathPrefixes  = (array) $input->getOption('path-prefix');
        $siteIdStrings = (array) $input->getOption('site-id');
        $siteIds       = \array_map('intval', \array_filter($siteIdStrings, static fn($v) => $v !== '' && $v !== null));
        $scope = (!empty($pathPrefixes) || !empty($siteIds))
            ? new MaintenanceScope($pathPrefixes, $siteIds)
            : null;

        try {
            $window = new ScheduleWindow(
                id: (string) $id,
                timezone: $timezone,
                reason: $reason,
                from: $hasOneTime ? new \DateTimeImmutable((string) $from) : null,
                to: $hasOneTime ? new \DateTimeImmutable((string) $to) : null,
                cronExpression: $hasRecurring ? (string) $cron : null,
                durationMinutes: $hasRecurring ? (int) $duration : null,
                announceBeforeMinutes: $announceBeforeMinutes,
                scope: $scope,
            );
        } catch (\Exception $e) {
            $io->error('Invalid window parameters: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $all      = $this->storage->findAll();
        $overlaps = $this->overlapDetector->detect($window, $all);
        if ($overlaps !== []) {
            $table = new Table($output);
            $table->setHeaders(['ID', 'Type', 'From / Cron', 'To / Duration']);
            foreach ($overlaps as $w) {
                $table->addRow([
                    $w->id,
                    $w->isRecurring() ? 'recurring' : 'one-time',
                    $w->isRecurring() ? $w->cronExpression : $w->from?->format(\DateTimeInterface::ATOM),
                    $w->isRecurring() ? $w->durationMinutes . 'min' : $w->to?->format(\DateTimeInterface::ATOM),
                ]);
            }
            $table->render();

            if (!$input->isInteractive()) {
                $io->warning('Overlapping windows detected. Proceeding non-interactively.');
            } else {
                if (!$io->confirm('Overlapping windows exist. Schedule anyway?', false)) {
                    return Command::SUCCESS;
                }
            }
        }

        if ($scope !== null) {
            $output->writeln('<comment>Note: overlap check does not consider scope — windows with non-overlapping scopes may still be flagged.</comment>');
        }

        if ($dry) {
            $io->success(\sprintf('[DRY-RUN] Window "%s" would be scheduled.', $window->id));
            return Command::SUCCESS;
        }

        $this->storage->add($window);
        $io->success(\sprintf('Scheduled window "%s".', $window->id));

        return Command::SUCCESS;
    }
}
