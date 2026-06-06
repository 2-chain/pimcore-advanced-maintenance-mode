<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\ExemptionMatch;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\CommandRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher\HttpRuleMatcher;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider;

final class ExemptionEvaluator
{
    /** @var list<HttpRule> */
    private array $httpRules;
    /** @var list<CommandRule> */
    private array $commandRules;
    /** @var list<IpRule> */
    private array $ipRules;

    /**
     * @param list<HttpRule|CommandRule|IpRule> $compiledRules
     */
    public function __construct(
        private readonly HttpRuleMatcher $httpMatcher,
        private readonly CommandRuleMatcher $commandMatcher,
        array $compiledRules,
    ) {
        $http = [];
        $cmd = [];
        $ip = [];
        foreach ($compiledRules as $rule) {
            match (true) {
                $rule instanceof HttpRule    => $http[] = $rule,
                $rule instanceof CommandRule => $cmd[] = $rule,
                $rule instanceof IpRule      => $ip[] = $rule,
            };
        }
        $this->httpRules = $http;
        $this->commandRules = $cmd;
        $this->ipRules = $ip;
    }

    public function evaluateRequest(Request $request): ?ExemptionMatch
    {
        $hit = $this->httpMatcher->matchRequest($request, $this->httpRules, $this->ipRules);
        if ($hit === null) {
            return null;
        }

        return new ExemptionMatch(
            ruleId: $hit->id,
            source: $hit->source,
            description: $this->describeHttp($hit, $request),
        );
    }

    public function evaluateCommand(string $commandName): ?ExemptionMatch
    {
        $hit = $this->commandMatcher->match($commandName, $this->commandRules);
        if ($hit === null) {
            return null;
        }

        return new ExemptionMatch(
            ruleId: $hit->id,
            source: $hit->source,
            description: \sprintf('command "%s" matches pattern "%s"', $commandName, $hit->namePattern),
        );
    }

    public static function create(
        HttpRuleMatcher $httpMatcher,
        CommandRuleMatcher $commandMatcher,
        CompiledRulesProvider $rulesProvider,
    ): self {
        return new self($httpMatcher, $commandMatcher, $rulesProvider->getRules());
    }

    private function describeHttp(HttpRule|IpRule $hit, Request $request): string
    {
        if ($hit instanceof IpRule) {
            return \sprintf('client IP %s matches %s', (string) $request->getClientIp(), $hit->ipOrCidr);
        }

        $parts = [];
        if ($hit->pathGlob !== null) {
            $parts[] = 'path=' . $hit->pathGlob;
        }
        if ($hit->routeName !== null) {
            $parts[] = 'route=' . $hit->routeName;
        }
        if ($hit->host !== null) {
            $parts[] = 'host=' . $hit->host;
        }
        if ($hit->methods !== []) {
            $parts[] = 'methods=' . \implode(',', $hit->methods);
        }

        return 'HTTP rule (' . \implode(', ', $parts) . ')';
    }
}
