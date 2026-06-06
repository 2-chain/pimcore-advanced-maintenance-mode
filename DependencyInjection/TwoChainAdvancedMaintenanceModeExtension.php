<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Throwable;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Command\DisableCommand;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\ConsoleCommandCheck;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\DatabasePingCheck;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HealthCheckRunner;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\HealthCheck\HttpGetCheck;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Maintenance\PostMaintenanceCheckTask;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\RuleCompiler;

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
        $container->setParameter('two_chain_advanced_maintenance_mode.default_ttl', $config['default_ttl']);
        $container->setParameter('two_chain_advanced_maintenance_mode.expiry_warning_threshold', $config['expiry_warning_threshold']);
        $container->setParameter('two_chain_advanced_maintenance_mode.public_status_enabled', $config['public_status_enabled']);
        $container->setParameter('two_chain_advanced_maintenance_mode.public_status_token', $config['public_status_token']);
        $container->setParameter('two_chain_advanced_maintenance_mode.auto_inject_banner',                   $config['pre_announce']['auto_inject_banner']);
        $container->setParameter('two_chain_advanced_maintenance_mode.default_threshold_minutes',            $config['pre_announce']['default_threshold_minutes']);
        $container->setParameter('two_chain_advanced_maintenance_mode.urgency_orange_minutes',               $config['pre_announce']['urgency_orange_minutes']);
        $container->setParameter('two_chain_advanced_maintenance_mode.urgency_red_minutes',                  $config['pre_announce']['urgency_red_minutes']);
        $container->setParameter('two_chain_advanced_maintenance_mode.dismiss_persistence',                  $config['pre_announce']['dismiss_persistence']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_on_pre_announce',                 $config['mail']['on_pre_announce']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_on_maintenance_start',            $config['mail']['on_maintenance_start']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_on_maintenance_end',              $config['mail']['on_maintenance_end']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_template',                        $config['mail']['template']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_pre_announce_template',           $config['mail']['pre_announce_template']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_maintenance_start_template',      $config['mail']['maintenance_start_template']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_maintenance_end_template',        $config['mail']['maintenance_end_template']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_recipients',                      $config['mail']['recipients']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_on_pre_announce_recipients',      $config['mail']['on_pre_announce_recipients']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_on_maintenance_start_recipients', $config['mail']['on_maintenance_start_recipients']);
        $container->setParameter('two_chain_advanced_maintenance_mode.mail_on_maintenance_end_recipients',   $config['mail']['on_maintenance_end_recipients']);
        $container->setParameter('two_chain_advanced_maintenance_mode.notification_webhooks',                $config['notifications']['webhooks']);

        $healthChecks = $config['health_checks'];
        $container->setParameter('two_chain_advanced_maintenance_mode.health_checks.enabled', $healthChecks['enabled']);
        $container->setParameter('two_chain_advanced_maintenance_mode.health_checks.retry_delay_seconds', $healthChecks['retry_delay_seconds']);
        $container->setParameter('two_chain_advanced_maintenance_mode.health_checks.checks', $healthChecks['checks']);

        if ($healthChecks['enabled'] && $healthChecks['checks'] !== []) {
            $checkDefinitions = [];
            foreach ($healthChecks['checks'] as $checkConfig) {
                switch ($checkConfig['type']) {
                    case 'http_get':
                        $httpClientRef = \class_exists(\Symfony\Component\HttpClient\HttpClient::class)
                            ? new Reference('http_client')
                            : null;
                        $def = new Definition(HttpGetCheck::class);
                        $def->setArguments([
                            '$url'            => $checkConfig['url'],
                            '$expectedStatus' => $checkConfig['expected_status'],
                            '$timeoutSeconds' => $checkConfig['timeout_seconds'],
                            '$httpClient'     => $httpClientRef,
                        ]);
                        $checkDefinitions[] = $def;
                        break;

                    case 'database_ping':
                        $connServiceId = 'doctrine.dbal.' . $checkConfig['connection'] . '_connection';
                        $def = new Definition(DatabasePingCheck::class);
                        $def->setArguments([
                            '$connection'     => new Reference($connServiceId),
                            '$connectionName' => $checkConfig['connection'],
                        ]);
                        $checkDefinitions[] = $def;
                        break;

                    case 'console_command':
                        $def = new Definition(ConsoleCommandCheck::class);
                        $def->setArguments([
                            '$command'        => $checkConfig['command'],
                            '$timeoutSeconds' => $checkConfig['timeout_seconds'],
                        ]);
                        $checkDefinitions[] = $def;
                        break;
                }
            }

            $container->getDefinition(HealthCheckRunner::class)
                ->setArgument('$checks', $checkDefinitions);

            $container->getDefinition(DisableCommand::class)
                ->setArgument('$runner', new Reference(HealthCheckRunner::class))
                ->setArgument('$retryDelaySec', $healthChecks['retry_delay_seconds']);

            $container->getDefinition(PostMaintenanceCheckTask::class)
                ->setArgument('$runner', new Reference(HealthCheckRunner::class));
        }

        // Feature J: default scope
        $scopeData = $config['selective_maintenance']['default_scope'];
        $hasScope  = !empty($scopeData['path_prefixes']) || !empty($scopeData['site_ids']);
        $container->setParameter(
            'two_chain_advanced_maintenance_mode.default_scope',
            $hasScope ? $scopeData : null,
        );

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
            ->getDefinition(\TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider::class)
            ->setArgument(
                '$rulesData',
                \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider::serialize($compiledRules),
            );
    }

    #[Override]
    public function getAlias(): string
    {
        return 'two_chain_advanced_maintenance_mode';
    }
}
