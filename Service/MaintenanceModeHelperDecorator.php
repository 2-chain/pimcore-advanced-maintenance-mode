<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Override;
use Pimcore\Tool\MaintenanceModeHelperInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class MaintenanceModeHelperDecorator implements MaintenanceModeHelperInterface
{
    public function __construct(
        private readonly MaintenanceModeHelperInterface $inner,
        private readonly RequestStack $requestStack,
    ) {}

    #[Override]
    public function isActive(?string $matchSessionId = null): bool
    {
        $request = $this->requestStack->getMainRequest();
        if ($request?->attributes->has('_advanced_maintenance_match')) {
            return false;
        }

        return $this->inner->isActive($matchSessionId);
    }

    #[Override]
    public function activate(string $sessionId): void
    {
        $this->inner->activate($sessionId);
    }

    #[Override]
    public function deactivate(): void
    {
        $this->inner->deactivate();
    }
}
