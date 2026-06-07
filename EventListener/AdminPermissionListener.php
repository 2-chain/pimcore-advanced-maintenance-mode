<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Override;

final class AdminPermissionListener implements EventSubscriberInterface
{
    private bool $registered = false;

    public function __construct(private readonly Connection $connection) {}

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 255]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if ($this->registered) {
            return;
        }
        $this->registerPermission();
        $this->registered = true;
    }

    private function registerPermission(): void
    {
        $ignore = $this->connection->getDatabasePlatform() instanceof SQLitePlatform
            ? 'INSERT OR IGNORE'
            : 'INSERT IGNORE';

        $this->connection->executeStatement(
            "$ignore INTO users_permission_definitions (`key`, `category`) VALUES (:key, :category)",
            ['key' => 'advanced_maintenance_manage', 'category' => 'Advanced Maintenance Mode'],
        );
    }
}
