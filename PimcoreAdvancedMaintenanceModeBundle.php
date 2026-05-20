<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle;

use Override;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\Compiler\AttributeExemptionDiscoveryPass;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\TwoChainAdvancedMaintenanceModeExtension;

final class PimcoreAdvancedMaintenanceModeBundle extends AbstractPimcoreBundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new AttributeExemptionDiscoveryPass());
    }

    #[Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new TwoChainAdvancedMaintenanceModeExtension();
    }
}
