<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection;

use Override;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('two_chain_advanced_maintenance_mode');
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('bypass_authenticated_admins')->defaultTrue()->end()
                ->scalarNode('default_retry_after')
                    ->defaultValue(300)
                    ->validate()
                        ->ifTrue(fn($v) => $v !== null && (!\is_int($v) || $v < 0))
                        ->thenInvalid('default_retry_after must be null or a non-negative integer')
                    ->end()
                ->end()
                ->arrayNode('builtin_exemptions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('bundle_own_commands')->defaultTrue()->end()
                        ->booleanNode('symfony_info_commands')->defaultTrue()->end()
                        ->booleanNode('loopback')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('exemptions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('commands')
                            ->beforeNormalization()
                                ->always(static function (array $v): array {
                                    return \array_map(static function ($item): array {
                                        if (\is_string($item)) {
                                            return ['pattern' => $item, 'id' => null];
                                        }
                                        if (\is_array($item)) {
                                            return [
                                                'pattern' => $item['pattern'] ?? '',
                                                'id'      => $item['id']      ?? null,
                                            ];
                                        }
                                        return ['pattern' => '', 'id' => null];
                                    }, $v);
                                })
                            ->end()
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('pattern')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('id')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('routes')
                            ->arrayPrototype()
                                ->validate()
                                    ->ifTrue(static fn(array $v): bool => empty($v['path']) && empty($v['route']))
                                    ->thenInvalid('Each route exemption must specify at least one of "path" or "route".')
                                ->end()
                                ->children()
                                    ->scalarNode('path')->defaultNull()->end()
                                    ->scalarNode('route')->defaultNull()->end()
                                    ->scalarNode('host')->defaultNull()->end()
                                    ->scalarNode('id')->defaultNull()->end()
                                    ->arrayNode('methods')
                                        ->scalarPrototype()
                                            ->validate()
                                                ->ifNotInArray($allowedMethods)
                                                ->thenInvalid('Invalid HTTP method (allowed: ' . \implode(', ', $allowedMethods) . ')')
                                            ->end()
                                        ->end()
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('ips')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('messenger_workers')->defaultFalse()->end()
                        ->booleanNode('scheduled_tasks')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
