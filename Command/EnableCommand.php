<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\BundleConfiguration;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceMailNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\MaintenanceWebhookNotifier;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Model\MaintenanceScope;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PreAnnounceStorage;

#[AsCommand(
    name: 'pimcore:advanced-maintenance:enable',
    description: 'Enable maintenance mode with optional reason and Retry-After metadata',
)]
final class EnableCommand extends Command
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly PreAnnounceStorage $preAnnounceStorage,
        private readonly MaintenanceMailNotifier $mailNotifier,
        private readonly MaintenanceWebhookNotifier $webhookNotifier,
        private readonly BundleConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason surfaced in template, header, banner')
            ->addOption('retry-after', null, InputOption::VALUE_REQUIRED, 'Override default_retry_after for this activation (seconds)')
            ->addOption('session-id', null, InputOption::VALUE_REQUIRED, "Activator session id (defaults to 'command-line-dummy-session-id')")
            ->addOption('expires-in', null, InputOption::VALUE_REQUIRED, 'TTL in minutes; maintenance auto-disables after this duration')
            ->addOption('path-prefix', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict maintenance to URL path prefix (repeatable)')
            ->addOption('site-id',     null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict maintenance to Pimcore site ID (repeatable)');
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
        $sessionId = $input->getOption('session-id') ?? 'command-line-dummy-session-id';
        \assert(\is_string($sessionId));

        $reason = $input->getOption('reason');
        $reason = \is_string($reason) && $reason !== '' ? $reason : null;

        $retryRaw = $input->getOption('retry-after');
        $retryAfter = null;
        if ($retryRaw !== null && $retryRaw !== '') {
            if (!\is_numeric($retryRaw) || (int) $retryRaw < 0) {
                $output->writeln('<error>--retry-after must be a non-negative integer</error>');
                return Command::FAILURE;
            }
            $retryAfter = (int) $retryRaw;
        }

        // Resolve TTL: --expires-in flag > YAML default_ttl > null
        $expiresInRaw = $input->getOption('expires-in');
        $ttlMinutes = null;
        if ($expiresInRaw !== null && $expiresInRaw !== '') {
            if (!\is_numeric($expiresInRaw) || (int) $expiresInRaw <= 0) {
                $output->writeln('<error>--expires-in must be a positive integer (minutes)</error>');
                return Command::FAILURE;
            }
            $ttlMinutes = (int) $expiresInRaw;
        } elseif ($this->config->defaultTtl !== null) {
            $ttlMinutes = $this->config->defaultTtl;
        }

        $expiresAtStr = null;
        if ($ttlMinutes !== null) {
            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(\sprintf('+%d minutes', $ttlMinutes));
            $expiresAtStr = $expiresAt->format(\DateTimeInterface::ATOM);
        }

        // Resolve scope: CLI options take precedence, then YAML default, then null (global).
        $pathPrefixes  = (array) $input->getOption('path-prefix');
        $siteIdStrings = (array) $input->getOption('site-id');
        $siteIds       = \array_map('intval', \array_filter($siteIdStrings, static fn($v) => $v !== '' && $v !== null));

        $scope = null;
        if ($pathPrefixes !== [] || $siteIds !== []) {
            $scope = new MaintenanceScope($pathPrefixes, $siteIds);
        } elseif ($this->config->defaultScope !== null) {
            $scope = $this->config->defaultScope;
        }

        $this->helper->activate($sessionId);
        $this->context->set(
            reason: $reason,
            retryAfter: $retryAfter,
            expiresAt: $expiresAtStr,
            originalTtlMinutes: $ttlMinutes,
        );
        $this->context->setScope($scope);
        $this->preAnnounceStorage->clear();

        if ($this->config->mailOnMaintenanceStart) {
            $this->mailNotifier->notifyMaintenanceStart($reason, $retryAfter, $sessionId);
        }
        if ($this->config->notificationWebhooks !== []) {
            $this->webhookNotifier->notifyMaintenanceStart($reason, $retryAfter, $sessionId);
        }

        if ($ttlMinutes !== null && $expiresAtStr !== null) {
            $this->logger->info('Maintenance mode enabled with TTL', [
                'expires_at'           => $expiresAtStr,
                'original_ttl_minutes' => $ttlMinutes,
            ]);
        }

        $output->writeln('<info>Maintenance mode enabled.</info>');
        $output->writeln(\sprintf('Scope:       %s', $this->formatScope($scope)));
        if ($reason !== null) {
            $output->writeln(\sprintf('Reason:      %s', $reason));
        }
        if ($retryAfter !== null) {
            $output->writeln(\sprintf('Retry-After: %ds', $retryAfter));
        }
        if ($ttlMinutes !== null && $expiresAtStr !== null) {
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $expiresAtStr);
            \assert($dt !== false);
            $output->writeln(\sprintf(
                'Expires at:  %s (%d min)',
                $dt->format('Y-m-d H:i:s \U\T\C'),
                $ttlMinutes,
            ));
        }

        return Command::SUCCESS;
    }

    private function formatScope(?MaintenanceScope $scope): string
    {
        if ($scope === null || $scope->isGlobal()) {
            return 'global';
        }

        $parts = [];
        if ($scope->pathPrefixes !== []) {
            $parts[] = \implode(', ', $scope->pathPrefixes);
        }
        if ($scope->siteIds !== []) {
            $parts[] = 'site ' . \implode(', ', $scope->siteIds);
        }

        return \implode(' · ', $parts);
    }
}
