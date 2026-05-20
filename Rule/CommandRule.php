<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule;

use InvalidArgumentException;

final readonly class CommandRule
{
    public function __construct(
        public string $id,
        public string $namePattern,
        public RuleSource $source,
    ) {
        if ($namePattern === '') {
            throw new InvalidArgumentException('CommandRule "' . $id . '" requires a non-empty namePattern.');
        }
    }

    /**
     * Support var_export() serialization (used by Symfony's container PhpDumper).
     *
     * @param array{id: string, namePattern: string, source: RuleSource} $properties
     */
    public static function __set_state(array $properties): static
    {
        return new static($properties['id'], $properties['namePattern'], $properties['source']);
    }
}
