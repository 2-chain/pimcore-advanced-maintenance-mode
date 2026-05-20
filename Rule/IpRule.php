<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule;

use InvalidArgumentException;

final readonly class IpRule
{
    public function __construct(
        public string $id,
        public string $ipOrCidr,
        public RuleSource $source,
    ) {
        if ($ipOrCidr === '') {
            throw new InvalidArgumentException('IpRule "' . $id . '" requires a non-empty ipOrCidr.');
        }
    }

    /**
     * Support var_export() serialization (used by Symfony's container PhpDumper).
     *
     * @param array{id: string, ipOrCidr: string, source: RuleSource} $properties
     */
    public static function __set_state(array $properties): static
    {
        return new static($properties['id'], $properties['ipOrCidr'], $properties['source']);
    }
}
