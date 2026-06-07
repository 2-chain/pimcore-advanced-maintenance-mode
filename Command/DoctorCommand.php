<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Cron\CronExpression;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Repository\ScheduleStorage;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:doctor',
    description: 'Run health checks for the advanced-maintenance bundle',
)]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly ScheduleStorage $scheduleStorage,
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
        $io      = new SymfonyStyle($input, $output);
        $checks  = $this->runChecks();
        $hasFail = false;

        foreach ($checks as [$label, $status, $detail]) {
            $icon = match ($status) {
                'ok'   => '<info>✓</info>',
                'warn' => '<comment>⚠</comment>',
                'fail' => '<error>✗</error>',
            };
            if ($status === 'fail') {
                $hasFail = true;
            }
            $io->writeln(\sprintf(' %s  %s%s', $icon, $label, $detail !== '' ? '  — ' . $detail : ''));
        }

        return $hasFail ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<array{string, 'ok'|'warn'|'fail', string}> [label, status, detail] */
    private function runChecks(): array
    {
        $checks = [];

        // 1. TmpStore read/write/delete
        if (\class_exists(\Pimcore\Model\Tool\TmpStore::class)) {
            try {
                $key = '__doctor_test_' . \uniqid('', true);
                \Pimcore\Model\Tool\TmpStore::set($key, ['ok' => true]);
                $entry = \Pimcore\Model\Tool\TmpStore::get($key);
                \Pimcore\Model\Tool\TmpStore::delete($key);
                $data = $entry?->getData();
                $ok = \is_array($data) && ($data['ok'] ?? null) === true;
                $checks[] = ['TmpStore read/write/delete', $ok ? 'ok' : 'fail', ''];
            } catch (\Throwable $e) {
                $checks[] = ['TmpStore read/write/delete', 'fail', $e->getMessage()];
            }
        } else {
            $checks[] = ['TmpStore read/write/delete', 'warn', 'Pimcore\\Model\\Tool\\TmpStore class not found'];
        }

        // 2. DB connection
        try {
            if (\class_exists(\Pimcore\Db::class)) {
                \Pimcore\Db::get()->executeQuery('SELECT 1');
                $checks[] = ['DB connection', 'ok', ''];
            } else {
                $checks[] = ['DB connection', 'warn', 'Pimcore\\Db not available in this environment'];
            }
        } catch (\Throwable $e) {
            $checks[] = ['DB connection', 'fail', $e->getMessage()];
        }

        // 3. History table exists (placeholder — Feature G)
        $checks[] = ['History table exists', 'warn', 'Feature G (history) not yet implemented'];

        // 4. Mailer configured
        $mailerDsn = $_ENV['MAILER_DSN'] ?? \getenv('MAILER_DSN') ?: null;
        if ($mailerDsn !== null && $mailerDsn !== '' && $mailerDsn !== 'null://null') {
            $checks[] = ['Mailer configured', 'ok', ''];
        } else {
            $checks[] = ['Mailer configured', 'warn', 'MAILER_DSN not set or is null transport'];
        }

        // 5. dragonmantank/cron-expression installed
        $checks[] = \class_exists(CronExpression::class)
            ? ['dragonmantank/cron-expression installed', 'ok', '']
            : ['dragonmantank/cron-expression installed', 'fail', 'Run: composer require dragonmantank/cron-expression:^3.3'];

        // 6. Stored cron expressions valid
        try {
            $cronErrors = [];
            foreach ($this->scheduleStorage->findAll() as $w) {
                if ($w->isRecurring() && $w->cronExpression !== null) {
                    try {
                        new CronExpression($w->cronExpression);
                    } catch (\Exception) {
                        $cronErrors[] = $w->id . ': ' . $w->cronExpression;
                    }
                }
            }
            $checks[] = $cronErrors === []
                ? ['Stored cron expressions valid', 'ok', '']
                : ['Stored cron expressions valid', 'fail', \implode(', ', $cronErrors)];
        } catch (\Throwable $e) {
            $checks[] = ['Stored cron expressions valid', 'warn', $e->getMessage()];
        }

        // 7. YAML values in valid ranges (we can only check env; config checked at boot)
        $checks[] = ['YAML values in valid ranges', 'ok', 'Validated at container build time'];

        // 8. symfony/http-client available
        $checks[] = \class_exists(\Symfony\Component\HttpClient\HttpClient::class)
            ? ['symfony/http-client available', 'ok', '']
            : ['symfony/http-client available', 'warn', 'Optional — needed for Feature I health checks'];

        // 9. advanced_maintenance_manage permission registered
        if (\class_exists(\Pimcore\Model\User\Permission\Definition::class)) {
            $perm = \Pimcore\Model\User\Permission\Definition::getByKey('advanced_maintenance_manage');
            $checks[] = $perm !== null
                ? ['advanced_maintenance_manage permission registered', 'ok', '']
                : ['advanced_maintenance_manage permission registered', 'warn', 'Permission not found — run pimcore:bundle:install'];
        } else {
            $checks[] = ['advanced_maintenance_manage permission registered', 'warn', 'Pimcore Permission\\Definition not available'];
        }

        return $checks;
    }
}
