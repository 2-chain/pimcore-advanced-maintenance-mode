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

#[AsCommand(
    name: 'pimcore:advanced-maintenance:schedule:list',
    description: 'List all scheduled maintenance windows',
)]
final class ScheduleListCommand extends Command
{
    public function __construct(private readonly ScheduleStorage $storage)
    {
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
        $windows = $this->storage->findAll();

        if ($windows === []) {
            $output->writeln('<comment>No scheduled maintenance windows.</comment>');
            return Command::SUCCESS;
        }

        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $table = new Table($output);
        $table->setHeaders(['ID', 'Type', 'Active', 'Reason', 'From / Cron', 'To / Duration', 'Timezone', 'Announce Before', 'Created By']);

        foreach ($windows as $w) {
            $table->addRow([
                $w->id,
                $w->isRecurring() ? 'recurring' : 'one-time',
                $w->isActiveAt($now) ? '<info>YES</info>' : 'no',
                $w->reason ?? '—',
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
}
