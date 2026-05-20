<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Matcher;

use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;

final class CommandRuleMatcher
{
    /**
     * @param list<CommandRule> $rules
     */
    public function match(string $commandName, array $rules): ?CommandRule
    {
        if ($commandName === '') {
            return null;
        }

        foreach ($rules as $rule) {
            if (\fnmatch($rule->namePattern, $commandName)) {
                return $rule;
            }
        }

        return null;
    }
}
