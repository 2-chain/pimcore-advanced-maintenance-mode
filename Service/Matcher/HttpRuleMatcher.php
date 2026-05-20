<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;

final class HttpRuleMatcher
{
    public function __construct(
        private readonly IpRuleMatcher $ipMatcher,
        private readonly RequestMatcherInterface $router,
    ) {
    }

    /**
     * @param list<HttpRule> $httpRules
     * @param list<IpRule>   $ipRules
     *
     * @return HttpRule|IpRule|null
     */
    public function matchRequest(Request $request, array $httpRules, array $ipRules): HttpRule|IpRule|null
    {
        // IP allow-list runs first — short-circuits route/path/host work.
        if ($ipRules !== []) {
            $ipHit = $this->ipMatcher->match($request->getClientIp(), $ipRules);
            if ($ipHit !== null) {
                return $ipHit;
            }
        }

        foreach ($httpRules as $rule) {
            if ($this->ruleMatches($rule, $request)) {
                return $rule;
            }
        }

        return null;
    }

    private function ruleMatches(HttpRule $rule, Request $request): bool
    {
        if ($rule->host !== null && !\fnmatch($rule->host, (string) $request->getHost())) {
            return false;
        }

        if ($rule->methods !== [] && !\in_array(\strtoupper($request->getMethod()), $rule->methods, true)) {
            return false;
        }

        if ($rule->pathGlob !== null && !\fnmatch($rule->pathGlob, $request->getPathInfo())) {
            return false;
        }

        if ($rule->routeName !== null && $this->resolveRouteName($request) !== $rule->routeName) {
            return false;
        }

        return true;
    }

    private function resolveRouteName(Request $request): ?string
    {
        $existing = $request->attributes->get('_route');
        if (\is_string($existing) && $existing !== '') {
            return $existing;
        }

        // RouterListener runs at priority 32 (after our 127), so _route
        // isn't populated yet at our priority. Resolve lazily.
        $cached = $request->attributes->get('_advanced_maintenance_resolved_route');
        if (\is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        try {
            $params = $this->router->matchRequest($request);
            $route = isset($params['_route']) && \is_string($params['_route']) ? $params['_route'] : '';
        } catch (ResourceNotFoundException|MethodNotAllowedException) {
            $route = '';
        }

        $request->attributes->set('_advanced_maintenance_resolved_route', $route);

        return $route === '' ? null : $route;
    }
}
