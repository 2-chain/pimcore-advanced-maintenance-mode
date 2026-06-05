<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\RuleCompiler;
use Throwable;

/** @phpstan-import-type ProcessedConfig from RuleCompiler */
final class TwoChainAdvancedMaintenanceModeExtension extends Extension implements PrependExtensionInterface
{
    #[Override]
    public function prepend(ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        if ($container->hasExtension('doctrine_migrations')) {
            $loader->load('doctrine_migrations.yml');
        }

        if ($container->hasExtension('doctrine')) {
            $loader->load('pimcore/doctrine.yaml');
        }
    }


    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var ProcessedConfig $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('two_chain_advanced_maintenance_mode.bypass_authenticated_admins', $config['bypass_authenticated_admins']);
        $container->setParameter('two_chain_advanced_maintenance_mode.default_retry_after', $config['default_retry_after']);
        $container->setParameter('two_chain_advanced_maintenance_mode.public_status_enabled', $config['public_status_enabled']);
        $container->setParameter('two_chain_advanced_maintenance_mode.public_status_token', $config['public_status_token']);

        $compiler = new \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\RuleCompiler();

        $yamlAndBuiltin = $compiler->compileFromConfig($config);

        // Env vars resolved through Symfony's env() param syntax. Default empty string.
        try {
            $raw = $container->resolveEnvPlaceholders('%env(default::ADVANCED_MAINTENANCE_EXEMPT_COMMANDS)%', true);
            $envCommands = \is_scalar($raw) ? (string) $raw : '';
        } catch (Throwable) {
            $envCommands = '';
        }

        try {
            $raw = $container->resolveEnvPlaceholders('%env(default::ADVANCED_MAINTENANCE_EXEMPT_ROUTES)%', true);
            $envRoutes = \is_scalar($raw) ? (string) $raw : '';
        } catch (Throwable) {
            $envRoutes = '';
        }

        try {
            $raw = $container->resolveEnvPlaceholders('%env(default::ADVANCED_MAINTENANCE_EXEMPT_IPS)%', true);
            $envIps = \is_scalar($raw) ? (string) $raw : '';
        } catch (Throwable) {
            $envIps = '';
        }

        $envRules = $compiler->compileFromEnv($envCommands, $envRoutes, $envIps);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Serialize rules to scalar arrays and store on CompiledRulesProvider.
        // XmlDumper (used by debug:container and cache:clear) rejects object
        // arguments, but handles scalar arrays fine. The provider hydrates back
        // to Rule DTOs at runtime via getRules().
        $compiledRules = [...$yamlAndBuiltin, ...$envRules];
        $container
            ->getDefinition(\TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\CompiledRulesProvider::class)
            ->setArgument(
                '$rulesData',
                \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\CompiledRulesProvider::serialize($compiledRules),
            );
    }

    #[Override]
    public function getAlias(): string
    {
        return 'two_chain_advanced_maintenance_mode';
    }
}
