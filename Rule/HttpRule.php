<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule;

use InvalidArgumentException;

final readonly class HttpRule
{
    /** @param list<string> $methods */
    public function __construct(
        public string $id,
        public RuleSource $source,
        public ?string $pathGlob = null,
        public ?string $routeName = null,
        public ?string $host = null,
        public array $methods = [],
    ) {
        if ($pathGlob === null && $routeName === null && $host === null && $methods === []) {
            throw new InvalidArgumentException('HttpRule "' . $id . '" must specify at least one of pathGlob, routeName, host, methods.');
        }
    }

    /**
     * Support var_export() serialization (used by Symfony's container PhpDumper).
     *
     * @param array{id: string, source: RuleSource, pathGlob?: string|null, routeName?: string|null, host?: string|null, methods?: list<string>} $properties
     */
    public static function __set_state(array $properties): static
    {
        return new static(
            $properties['id'],
            $properties['source'],
            $properties['pathGlob'] ?? null,
            $properties['routeName'] ?? null,
            $properties['host'] ?? null,
            $properties['methods'] ?? [],
        );
    }
}
