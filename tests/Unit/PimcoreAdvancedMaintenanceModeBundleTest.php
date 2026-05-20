<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\TwoChainAdvancedMaintenanceModeExtension;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\PimcoreAdvancedMaintenanceModeBundle;

final class PimcoreAdvancedMaintenanceModeBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsOurExtension(): void
    {
        $bundle = new PimcoreAdvancedMaintenanceModeBundle();
        $ext = $bundle->getContainerExtension();

        self::assertInstanceOf(TwoChainAdvancedMaintenanceModeExtension::class, $ext);
    }

    public function testExtensionAliasMatchesConfigRootKey(): void
    {
        $ext = new TwoChainAdvancedMaintenanceModeExtension();

        self::assertSame('two_chain_advanced_maintenance_mode', $ext->getAlias());
    }

    public function testExtensionLoadsServicesYaml(): void
    {
        $container = new ContainerBuilder();
        $ext = new TwoChainAdvancedMaintenanceModeExtension();

        $ext->load([], $container);

        // services.yaml is loaded and extension wired — the call must not throw.
        self::assertTrue($container->hasParameter('two_chain_advanced_maintenance_mode.bypass_authenticated_admins'));
    }
}
