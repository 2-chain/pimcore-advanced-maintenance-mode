<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Monolog\Attribute\WithMonologChannel;
use Override;
use Pimcore\Maintenance\TaskInterface;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Psr\Log\LoggerInterface;

#[WithMonologChannel('advanced_maintenance_mode')]
final class PostMaintenanceCacheCleanup implements TaskInterface
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $helper,
        private readonly LoggerInterface $logger,
    ) {}

    #[Override]
    public function execute(): void
    {
        if ($this->helper->isActive()) {
            return;
        }

        if (\class_exists(\Pimcore\Cache::class)) {
            try {
                \Pimcore\Cache::clearAll();
                $this->logger->info('PostMaintenanceCacheCleanup: cleared Pimcore cache after maintenance.');
            } catch (\Throwable $e) {
                $this->logger->warning('PostMaintenanceCacheCleanup: cache clear failed.', ['error' => $e->getMessage()]);
            }
        }
    }
}
