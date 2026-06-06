<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:heartbeat',
    description: 'Refresh the TTL of the current manual maintenance activation',
)]
final class HeartbeatCommand extends Command
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'expires-in',
            null,
            InputOption::VALUE_REQUIRED,
            'Override TTL in minutes for this heartbeat (positive integer)',
        );
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
        if (!$this->helper->isActive()) {
            $output->writeln('<error>Maintenance mode is not active.</error>');
            return Command::FAILURE;
        }

        if ($this->context->getActivatedByScheduleWindowId() !== null) {
            $output->writeln('<error>Heartbeat is not applicable to schedule-activated maintenance.</error>');
            return Command::FAILURE;
        }

        $expiresInRaw = $input->getOption('expires-in');
        $flagTtl = null;
        if ($expiresInRaw !== null && $expiresInRaw !== '') {
            if (!\is_numeric($expiresInRaw) || (int) $expiresInRaw <= 0) {
                $output->writeln('<error>--expires-in must be a positive integer (minutes)</error>');
                return Command::FAILURE;
            }
            $flagTtl = (int) $expiresInRaw;
        }

        $existingExpiresAt = $this->context->getExpiresAt();
        $originalTtl = $this->context->getOriginalTtlMinutes();

        if ($existingExpiresAt === null && $flagTtl === null) {
            $output->writeln(
                '<error>No TTL is set on the current activation. Use --expires-in to add one, or run disable when done.</error>',
            );
            return Command::FAILURE;
        }

        $ttlMinutes = $flagTtl ?? $originalTtl ?? 0;

        $newExpiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(\sprintf('+%d minutes', $ttlMinutes));

        $newOriginalTtl = $flagTtl ?? $originalTtl;

        $this->context->updateExpiry($newExpiresAt, $newOriginalTtl, null);

        $this->logger->info('Heartbeat accepted', [
            'expires_at'           => $newExpiresAt->format(\DateTimeInterface::ATOM),
            'original_ttl_minutes' => $newOriginalTtl,
        ]);

        $output->writeln('<info>Heartbeat recorded. Maintenance mode TTL extended.</info>');
        $output->writeln(\sprintf(
            'New expiry:  %s (%d min)',
            $newExpiresAt->format('Y-m-d H:i:s \U\T\C'),
            $ttlMinutes,
        ));

        return Command::SUCCESS;
    }
}
