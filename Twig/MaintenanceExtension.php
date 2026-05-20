<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Twig;

use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\ActivationContext;

final class MaintenanceExtension extends AbstractExtension
{
    public function __construct(private readonly ActivationContext $context)
    {
    }

    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('maintenance_reason', $this->maintenanceReason(...)),
            new TwigFunction('maintenance_retry_after', $this->maintenanceRetryAfter(...)),
        ];
    }

    public function maintenanceReason(): ?string
    {
        return $this->context->getReason();
    }

    public function maintenanceRetryAfter(): ?int
    {
        return $this->context->getRetryAfter();
    }
}
