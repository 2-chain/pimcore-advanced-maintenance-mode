<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Override;
use Pimcore\Maintenance\TaskInterface;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\PendingHealthCheckStorage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class ExpiryEnforcementTask implements TaskInterface
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly ActivationContext $context,
        private readonly BundleConfiguration $config,
        private readonly LoggerInterface $logger,
        private readonly ?PendingHealthCheckStorage $pendingStorage = null,
    ) {}

    #[Override]
    public function execute(): void
    {
        // Pre-condition A: mode must be active
        if (!$this->helper->isActive()) {
            return;
        }

        // Pre-condition B: skip schedule-managed activations
        if ($this->context->getActivatedByScheduleWindowId() !== null) {
            return;
        }

        // Pre-condition C: skip if no TTL is set
        $expiresAt = $this->context->getExpiresAt();
        if ($expiresAt === null) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $secondsRemaining = $expiresAt->getTimestamp() - $now->getTimestamp();

        if ($secondsRemaining <= 0) {
            $this->helper->deactivate();
            $this->pendingStorage?->write('ttl_expired');
            $this->context->clear();
            $this->logger->info('Maintenance mode auto-deactivated: TTL expired');
            return;
        }

        $threshold = $this->config->expiryWarningThreshold;
        if ($threshold === null) {
            return;
        }

        $thresholdSeconds = $threshold * 60;
        if ($secondsRemaining > $thresholdSeconds) {
            return;
        }

        // Suppress re-warning if already warned within the threshold window
        $warnedAt = $this->context->getWarningEmittedAt();
        if ($warnedAt !== null && $warnedAt->getTimestamp() >= $now->getTimestamp() - $thresholdSeconds) {
            return;
        }

        $minutesRemaining = (int) \ceil($secondsRemaining / 60);
        $this->logger->warning(
            'Maintenance mode TTL expiring in {minutes_remaining} min at {expires_at}',
            [
                'minutes_remaining' => $minutesRemaining,
                'expires_at'        => $expiresAt->format(DateTimeInterface::ATOM),
            ],
        );

        $this->context->updateExpiry(
            $expiresAt,
            $this->context->getOriginalTtlMinutes(),
            $now,
        );
    }
}
