<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;

/**
 * @phpstan-type ProcessedConfig array{
 *     bypass_authenticated_admins: bool,
 *     default_retry_after: int|null,
 *     builtin_exemptions: BuiltinExemptions,
 *     exemptions: YamlExemptions,
 * }
 * @phpstan-type BuiltinExemptions array{
 *     bundle_own_commands: bool,
 *     symfony_info_commands: bool,
 *     loopback: bool,
 * }
 * @phpstan-type YamlExemptions array{
 *     commands: list<array{pattern: string, id: string|null}>,
 *     routes: list<array{path: string|null, route: string|null, host: string|null, id: string|null, methods: list<string>}>,
 *     ips: list<string>,
 *     messenger_workers: bool,
 *     scheduled_tasks: bool,
 * }
 */
final class RuleCompiler
{
    /**
     * @param ProcessedConfig $config processed config from Configuration
     *
     * @return list<HttpRule|CommandRule|IpRule>
     */
    public function compileFromConfig(array $config): array
    {
        $rules = [];
        $rules = [...$rules, ...$this->compileBuiltins($config['builtin_exemptions'])];
        $rules = [...$rules, ...$this->compileYaml($config['exemptions'])];

        return $rules;
    }

    /**
     * @param YamlExemptions $exemptions
     * @return list<HttpRule|CommandRule|IpRule>
     */
    private function compileYaml(array $exemptions): array
    {
        $out = [];

        foreach ($exemptions['commands'] as $idx => $cmd) {
            $out[] = new CommandRule(
                id: $cmd['id'] ?? 'yaml-commands-' . $idx,
                namePattern: $cmd['pattern'],
                source: RuleSource::Yaml,
            );
        }

        foreach ($exemptions['routes'] as $idx => $r) {
            $out[] = new HttpRule(
                id: $r['id'] ?? 'yaml-routes-' . $idx,
                source: RuleSource::Yaml,
                pathGlob: $r['path'],
                routeName: $r['route'],
                host: $r['host'],
                methods: $r['methods'],
            );
        }

        foreach ($exemptions['ips'] as $idx => $ip) {
            $out[] = new IpRule(
                id: 'yaml-ips-' . $idx,
                ipOrCidr: $ip,
                source: RuleSource::Yaml,
            );
        }

        if ($exemptions['messenger_workers'] === true) {
            $out[] = new CommandRule('messenger-workers', 'messenger:*', RuleSource::Builtin);
        }

        if ($exemptions['scheduled_tasks'] === true) {
            $out[] = new CommandRule('scheduled-tasks', 'pimcore:scheduler:*', RuleSource::Builtin);
        }

        return $out;
    }

    /**
     * @return list<HttpRule|CommandRule|IpRule>
     */
    public function compileFromEnv(string $commands, string $routes, string $ips): array
    {
        $out = [];

        foreach ($this->splitCsv($commands) as $idx => $pattern) {
            $out[] = new CommandRule(
                id: 'env-commands-' . $idx,
                namePattern: $pattern,
                source: RuleSource::Env,
            );
        }

        foreach ($this->splitCsv($routes) as $idx => $path) {
            $out[] = new HttpRule(
                id: 'env-routes-' . $idx,
                source: RuleSource::Env,
                pathGlob: $path,
            );
        }

        foreach ($this->splitCsv($ips) as $idx => $ip) {
            $out[] = new IpRule(
                id: 'env-ips-' . $idx,
                ipOrCidr: $ip,
                source: RuleSource::Env,
            );
        }

        return $out;
    }

    /**
     * @param BuiltinExemptions $builtins
     * @return list<HttpRule|CommandRule|IpRule>
     */
    private function compileBuiltins(array $builtins): array
    {
        $out = [];

        if ($builtins['bundle_own_commands'] === true) {
            $out[] = new CommandRule('bundle-own-commands', 'pimcore:advanced-maintenance:*', RuleSource::Builtin);
        }

        if ($builtins['symfony_info_commands'] === true) {
            foreach (['help', 'list', '_complete', 'completion', 'about'] as $i => $name) {
                $out[] = new CommandRule('symfony-info-' . $i, $name, RuleSource::Builtin);
            }
        }

        if ($builtins['loopback'] === true) {
            $out[] = new IpRule('loopback-ipv4', '127.0.0.1', RuleSource::Builtin);
            $out[] = new IpRule('loopback-ipv6', '::1', RuleSource::Builtin);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function splitCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return \array_values(\array_filter(
            \array_map('trim', \explode(',', $value)),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
