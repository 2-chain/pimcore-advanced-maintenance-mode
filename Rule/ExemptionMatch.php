<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule;

final readonly class ExemptionMatch
{
    public function __construct(
        public string $ruleId,
        public RuleSource $source,
        public string $description,
    ) {}
}
