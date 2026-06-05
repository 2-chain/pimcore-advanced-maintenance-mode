<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\EventListener\AdminPermissionListener;

final class AdminPermissionListenerTest extends TestCase
{
    private function mainEvent(): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function subEvent(): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/fragment'),
            HttpKernelInterface::SUB_REQUEST,
        );
    }

    public function testFirstMainRequestRegistersPermission(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT'),
                self::identicalTo(['key' => 'advanced_maintenance_manage', 'category' => 'Advanced Maintenance Mode']),
            );

        $listener = new AdminPermissionListener($connection);
        $listener->onKernelRequest($this->mainEvent());
    }

    public function testSubsequentMainRequestsDoNotRepeatRegistration(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement');

        $listener = new AdminPermissionListener($connection);
        $listener->onKernelRequest($this->mainEvent());
        $listener->onKernelRequest($this->mainEvent());
    }

    public function testSubRequestDoesNotRegister(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $listener = new AdminPermissionListener($connection);
        $listener->onKernelRequest($this->subEvent());
    }
}
