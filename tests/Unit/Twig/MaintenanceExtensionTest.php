<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ContextStorageInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Twig\MaintenanceExtension;

final class MaintenanceExtensionTest extends TestCase
{
    private function context(?string $reason): ActivationContext
    {
        $storage = new class ($reason) implements ContextStorageInterface {
            public function __construct(private readonly ?string $reason) {}
            public function load(): array
            {
                return ['reason' => $this->reason, 'retry_after' => null];
            }
            public function save(?string $reason, ?int $retryAfter): void {}
            public function clear(): void {}
        };
        return new ActivationContext($storage);
    }

    public function testMaintenanceReasonFunctionReturnsValue(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => "{{ maintenance_reason() ?? 'no reason' }}"]));
        $twig->addExtension(new MaintenanceExtension($this->context('DB migration v3.5')));

        self::assertSame('DB migration v3.5', $twig->render('t'));
    }

    public function testMaintenanceReasonReturnsNullWhenUnset(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => "{{ maintenance_reason() ?? 'no reason' }}"]));
        $twig->addExtension(new MaintenanceExtension($this->context(null)));

        self::assertSame('no reason', $twig->render('t'));
    }
}
