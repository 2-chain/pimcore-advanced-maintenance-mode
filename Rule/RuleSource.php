<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule;

enum RuleSource: string
{
    case Yaml      = 'yaml';
    case Env       = 'env';
    case Attribute = 'attribute';
    case Builtin   = 'builtin';
}
