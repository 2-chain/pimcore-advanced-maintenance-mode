<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;

/**
 * @phpstan-type HttpRuleData    array{type: 'http', id: string, source: string, pathGlob: string|null, routeName: string|null, host: string|null, methods: list<string>}
 * @phpstan-type CommandRuleData array{type: 'command', id: string, source: string, namePattern: string}
 * @phpstan-type IpRuleData      array{type: 'ip', id: string, source: string, ipOrCidr: string}
 * @phpstan-type SerializedRule  HttpRuleData|CommandRuleData|IpRuleData
 */
final class CompiledRulesProvider
{
    /** @var list<HttpRule|CommandRule|IpRule>|null */
    private ?array $hydrated = null;

    /**
     * @param list<SerializedRule> $rulesData
     */
    public function __construct(private readonly array $rulesData) {}

    /**
     * @return list<HttpRule|CommandRule|IpRule>
     */
    public function getRules(): array
    {
        if ($this->hydrated === null) {
            $this->hydrated = $this->hydrate();
        }
        return $this->hydrated;
    }

    /**
     * @return list<HttpRule|CommandRule|IpRule>
     */
    private function hydrate(): array
    {
        $out = [];
        foreach ($this->rulesData as $data) {
            $source = RuleSource::from($data['source']);
            $out[] = match ($data['type']) {
                'http'    => new HttpRule(
                    id: $data['id'],
                    source: $source,
                    pathGlob: $data['pathGlob'],
                    routeName: $data['routeName'],
                    host: $data['host'],
                    methods: $data['methods'],
                ),
                'command' => new CommandRule(
                    id: $data['id'],
                    namePattern: $data['namePattern'],
                    source: $source,
                ),
                'ip'      => new IpRule(
                    id: $data['id'],
                    ipOrCidr: $data['ipOrCidr'],
                    source: $source,
                ),
            };
        }
        return $out;
    }

    /**
     * Serialize a list of Rule DTOs into the scalar shape stored in the container.
     *
     * @param list<HttpRule|CommandRule|IpRule> $rules
     * @return list<SerializedRule>
     */
    public static function serialize(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $out[] = match (true) {
                $rule instanceof HttpRule => [
                    'type'      => 'http',
                    'id'        => $rule->id,
                    'source'    => $rule->source->value,
                    'pathGlob'  => $rule->pathGlob,
                    'routeName' => $rule->routeName,
                    'host'      => $rule->host,
                    'methods'   => $rule->methods,
                ],
                $rule instanceof CommandRule => [
                    'type'        => 'command',
                    'id'          => $rule->id,
                    'source'      => $rule->source->value,
                    'namePattern' => $rule->namePattern,
                ],
                $rule instanceof IpRule => [
                    'type'     => 'ip',
                    'id'       => $rule->id,
                    'source'   => $rule->source->value,
                    'ipOrCidr' => $rule->ipOrCidr,
                ],
            };
        }
        return $out;
    }
}
