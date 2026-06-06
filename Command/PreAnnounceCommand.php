<?php
declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceData;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:pre-announce',
    description: 'Set a pre-announcement countdown banner for upcoming maintenance',
)]
final class PreAnnounceCommand extends Command
{
    public function __construct(
        private readonly PreAnnounceStorage $storage,
        private readonly MaintenanceMailNotifier $mailNotifier,
        private readonly MaintenanceWebhookNotifier $webhookNotifier,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'Maintenance start datetime (Y-m-d H:i:s or ISO8601) in --timezone')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Human-readable reason')
            ->addOption('announce-before', null, InputOption::VALUE_REQUIRED, 'Minutes before start to show banner (overrides config default)')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Timezone for --at interpretation', 'UTC');
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
        $atRaw = $input->getOption('at');
        if (!\is_string($atRaw) || $atRaw === '') {
            $output->writeln('<error>--at is required</error>');
            return Command::FAILURE;
        }

        $tzRaw = $input->getOption('timezone');
        $tz    = \is_string($tzRaw) ? $tzRaw : 'UTC';
        try {
            $timezone = new \DateTimeZone($tz);
        } catch (\Exception) {
            $output->writeln('<error>Invalid timezone: ' . $tz . '</error>');
            return Command::FAILURE;
        }

        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $atRaw, $timezone)
            ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $atRaw);
        if ($at === false) {
            $output->writeln('<error>Cannot parse --at: "' . $atRaw . '". Use Y-m-d H:i:s or ISO8601.</error>');
            return Command::FAILURE;
        }

        $atUtc = $at->setTimezone(new \DateTimeZone('UTC'));
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($atUtc <= $now) {
            $output->writeln('<error>--at must be in the future</error>');
            return Command::FAILURE;
        }

        $announceRaw    = $input->getOption('announce-before');
        $announceMinutes = null;
        if ($announceRaw !== null && $announceRaw !== '') {
            if (!\is_numeric($announceRaw) || (int) $announceRaw <= 0) {
                $output->writeln('<error>--announce-before must be a positive integer</error>');
                return Command::FAILURE;
            }
            $announceMinutes = (int) $announceRaw;
        }

        $reasonRaw = $input->getOption('reason');
        $reason    = \is_string($reasonRaw) && $reasonRaw !== '' ? $reasonRaw : null;

        $data = new PreAnnounceData(
            at: $atUtc,
            timezone: $tz,
            reason: $reason,
            announceBeforeMinutes: $announceMinutes,
        );
        $this->storage->save($data);
        $this->mailNotifier->notifyPreAnnounce($data);
        $this->webhookNotifier->notifyPreAnnounce($data);

        $bannerFrom = $announceMinutes !== null
            ? $atUtc->modify('-' . $announceMinutes . ' minutes')
            : null;

        $output->writeln('<info>Pre-announcement set.</info>');
        $output->writeln('Maintenance at: ' . $atUtc->format('Y-m-d H:i:s') . ' UTC');
        if ($reason !== null) {
            $output->writeln('Reason:         ' . $reason);
        }
        if ($bannerFrom !== null) {
            $output->writeln(\sprintf(
                'Banner shows:   from %s UTC (%d min before)',
                $bannerFrom->format('Y-m-d H:i:s'),
                $announceMinutes,
            ));
        }

        return Command::SUCCESS;
    }
}
