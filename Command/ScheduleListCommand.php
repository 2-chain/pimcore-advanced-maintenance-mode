<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;
use DateTimeImmutable;
use DateTimeZone;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:schedule:list',
    description: 'List all scheduled maintenance windows',
)]
final class ScheduleListCommand extends Command
{
    public function __construct(
        private readonly ScheduleStorage $storage,
        private readonly PreAnnounceStorage $preAnnounceStorage,
    ) {
        parent::__construct();
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
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $manual  = $this->preAnnounceStorage->load();

        if ($manual !== null && $manual->at > $now) {
            $label = $manual->at->format('Y-m-d H:i') . ' UTC';
            if ($manual->reason !== null) {
                $label .= ' — ' . $manual->reason;
            }
            $output->writeln('<comment>Manual pre-announcement:</comment> ' . $label);
            $output->writeln('');
        }

        $windows = $this->storage->findAll();

        if ($windows === []) {
            $output->writeln('<comment>No scheduled maintenance windows.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Type', 'Active', 'Reason', 'Scope', 'From / Cron', 'To / Duration', 'Timezone', 'Announce Before', 'Created By']);

        foreach ($windows as $w) {
            $table->addRow([
                $w->id,
                $w->isRecurring() ? 'recurring' : 'one-time',
                $w->isActiveAt($now) ? '<info>YES</info>' : 'no',
                $w->reason ?? '—',
                $this->formatScope($w->scope),
                $w->isRecurring() ? $w->cronExpression : $w->from?->format('Y-m-d H:i') . ' UTC',
                $w->isRecurring() ? $w->durationMinutes . ' min' : $w->to?->format('Y-m-d H:i') . ' UTC',
                $w->timezone,
                $w->announceBeforeMinutes > 0 ? $w->announceBeforeMinutes . ' min' : '—',
                $w->createdByUsername !== '' ? $w->createdByUsername : '—',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function formatScope(?\TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope $scope): string
    {
        if ($scope === null || $scope->isGlobal()) {
            return 'global';
        }

        $parts = [];
        if ($scope->pathPrefixes !== []) {
            $paths = \implode(', ', $scope->pathPrefixes);
            $parts[] = \mb_strlen($paths) > 30 ? \mb_substr($paths, 0, 29) . '…' : $paths;
        }
        if ($scope->siteIds !== []) {
            $parts[] = 'site ' . \implode(', ', $scope->siteIds);
        }

        $result = \implode(' · ', $parts);
        return \mb_strlen($result) > 30 ? \mb_substr($result, 0, 29) . '…' : $result;
    }
}
