<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\DependencyInjection\ContainerBuilder;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\Compiler\AttributeExemptionDiscoveryPass;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\TwoChainAdvancedMaintenanceModeExtension;

$container = new ContainerBuilder();
(new TwoChainAdvancedMaintenanceModeExtension())->load([], $container);
$container->addCompilerPass(new AttributeExemptionDiscoveryPass());
$container->compile();

echo "Compiled rules: " . count((array) $container->getParameter('two_chain_advanced_maintenance_mode.compiled_rules')) . "\n";
echo "Services registered:\n";
foreach ($container->getServiceIds() as $id) {
    if (str_starts_with($id, 'TwoChain\\')) {
        echo "  - $id\n";
    }
}
