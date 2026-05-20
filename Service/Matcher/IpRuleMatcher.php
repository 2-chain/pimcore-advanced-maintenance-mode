<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher;

use Symfony\Component\HttpFoundation\IpUtils;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\IpRule;

final class IpRuleMatcher
{
    /**
     * @param list<IpRule> $rules
     */
    public function match(?string $clientIp, array $rules): ?IpRule
    {
        if ($clientIp === null || $clientIp === '') {
            return null;
        }

        foreach ($rules as $rule) {
            if (IpUtils::checkIp($clientIp, $rule->ipOrCidr)) {
                return $rule;
            }
        }

        return null;
    }
}
