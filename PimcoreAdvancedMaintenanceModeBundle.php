<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle;

use Override;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\PimcoreBundleAdminClassicInterface;
use Pimcore\Extension\Bundle\Traits\BundleAdminClassicTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\Compiler\AttributeExemptionDiscoveryPass;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\TwoChainAdvancedMaintenanceModeExtension;

final class PimcoreAdvancedMaintenanceModeBundle extends AbstractPimcoreBundle implements PimcoreBundleAdminClassicInterface
{
    use BundleAdminClassicTrait;

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new AttributeExemptionDiscoveryPass());
    }

    #[Override]
    public function getJsPaths(): array
    {
        return [
            '/bundles/pimcoreadvancedmaintenancemode/js/admin/MaintenanceStatusPortlet.js',
            '/bundles/pimcoreadvancedmaintenancemode/js/admin/MaintenanceWindowsPanel.js',
        ];
    }

    #[Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new TwoChainAdvancedMaintenanceModeExtension();
    }
}
