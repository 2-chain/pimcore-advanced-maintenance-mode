<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\DependencyInjection\Compiler;

use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Attribute\ExemptFromMaintenance;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\CommandRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\HttpRule;
use TwoChain\PimcoreAdvancedMaintenanceModeBundle\Rule\RuleSource;

final class AttributeExemptionDiscoveryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(\TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider::class)) {
            return;
        }
        $providerDef = $container->getDefinition(\TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider::class);

        /** @var list<array<string, mixed>> $existing */
        $existing = (array) $providerDef->getArgument('$rulesData');

        /** @var list<HttpRule|CommandRule> $discoveredRules */
        $discoveredRules = [];

        // A single PHP class may be registered as multiple service definitions
        // (e.g. controllers are often aliased). Reflecting twice would emit the
        // same attribute-derived rule twice. Track seen classes.
        /** @var array<class-string, true> $seen */
        $seen = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if ($class === null) {
                continue;
            }

            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;

            // class_exists() triggers the autoloader, which under Symfony's
            // DebugClassLoader rethrows ClassNotFoundError if a parent/trait
            // is missing (e.g. doctrine-bridge's EntityType extends
            // Symfony\Component\Form\AbstractType when symfony/form is absent).
            // Reflection itself can also throw when methods reference missing
            // types. Either way: if we can't fully resolve the class, it can't
            // carry our attribute — skip it.
            try {
                if (!\class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                $classAttributes = $reflection->getAttributes(ExemptFromMaintenance::class);
                $methods = $reflection->getMethods();
            } catch (Throwable) {
                continue;
            }

            foreach ($classAttributes as $attr) {
                $discoveredRules[] = $this->buildFromClass($reflection, $attr->newInstance());
            }

            foreach ($methods as $method) {
                try {
                    $methodAttributes = $method->getAttributes(ExemptFromMaintenance::class);
                } catch (Throwable) {
                    continue;
                }
                foreach ($methodAttributes as $attr) {
                    $discoveredRules[] = $this->buildFromMethod($method, $attr->newInstance());
                }
            }
        }

        if ($discoveredRules !== []) {
            $serialized = \TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service\Provider\CompiledRulesProvider::serialize($discoveredRules);
            $providerDef->setArgument('$rulesData', [...$existing, ...$serialized]);
        }
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function buildFromClass(ReflectionClass $class, ExemptFromMaintenance $attr): CommandRule|HttpRule
    {
        $id = $attr->id ?? 'attribute-' . \strtolower(\str_replace('\\', '-', $class->getName()));

        $command = $this->firstRouteOrCommandAttribute($class->getAttributes(AsCommand::class));
        if ($command !== null) {
            return new CommandRule(id: $id, namePattern: $command->name, source: RuleSource::Attribute);
        }

        $route = $this->getRouteAttribute($class);
        if ($route !== null) {
            return $this->httpRuleFromRoute($id, $route);
        }

        throw new LogicException(\sprintf(
            'Class %s has #[ExemptFromMaintenance] but no #[AsCommand] or #[Route] sibling — '
            . 'the attribute is meaningless without one of these.',
            $class->getName(),
        ));
    }

    private function buildFromMethod(ReflectionMethod $method, ExemptFromMaintenance $attr): HttpRule
    {
        $id = $attr->id ?? 'attribute-' . \strtolower(\str_replace('\\', '-', $method->class)) . '-' . $method->getName();

        $route = $this->getRouteAttributeFromMethod($method);
        if ($route !== null) {
            return $this->httpRuleFromRoute($id, $route);
        }

        throw new LogicException(\sprintf(
            'Method %s::%s has #[ExemptFromMaintenance] but no #[Route] sibling — '
            . 'method-level exemption requires a route attribute on the same method.',
            $method->class,
            $method->getName(),
        ));
    }

    private function httpRuleFromRoute(string $id, Route $route): HttpRule
    {
        if ($route->name !== null && $route->name !== '') {
            return new HttpRule(
                id: $id,
                source: RuleSource::Attribute,
                routeName: $route->name,
                methods: $this->normalizeMethods($route->methods),
            );
        }

        $path = \is_string($route->path) ? $route->path : '';
        if ($path === '') {
            throw new LogicException(\sprintf(
                '#[ExemptFromMaintenance] on a #[Route] without name or path — id "%s"',
                $id,
            ));
        }

        return new HttpRule(
            id: $id,
            source: RuleSource::Attribute,
            pathGlob: $path,
            methods: $this->normalizeMethods($route->methods),
        );
    }

    /**
     * Get Route attribute from a class, checking both Attribute\Route and Annotation\Route namespaces.
     *
     * @param ReflectionClass<object> $class
     */
    private function getRouteAttribute(ReflectionClass $class): ?Route
    {
        // Try Attribute\Route (canonical)
        $attrs = $class->getAttributes(Route::class);
        if ($attrs !== []) {
            return $attrs[0]->newInstance();
        }

        // Try Annotation\Route alias (used in fixtures and older code)
        if (\class_exists(\Symfony\Component\Routing\Annotation\Route::class)) {
            $attrs = $class->getAttributes(\Symfony\Component\Routing\Annotation\Route::class);
            if ($attrs !== []) {
                return $attrs[0]->newInstance();
            }
        }

        return null;
    }

    /**
     * Get Route attribute from a method, checking both namespaces.
     */
    private function getRouteAttributeFromMethod(ReflectionMethod $method): ?Route
    {
        // Try Attribute\Route (canonical)
        $attrs = $method->getAttributes(Route::class);
        if ($attrs !== []) {
            return $attrs[0]->newInstance();
        }

        // Try Annotation\Route alias (used in fixtures and older code)
        if (\class_exists(\Symfony\Component\Routing\Annotation\Route::class)) {
            $attrs = $method->getAttributes(\Symfony\Component\Routing\Annotation\Route::class);
            if ($attrs !== []) {
                return $attrs[0]->newInstance();
            }
        }

        return null;
    }

    /**
     * @template T of object
     * @param list<ReflectionAttribute<T>> $attrs
     * @return T|null
     */
    private function firstRouteOrCommandAttribute(array $attrs): ?object
    {
        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * @param array<string>|string $methods
     * @return list<string>
     */
    private function normalizeMethods(array|string $methods): array
    {
        if (\is_string($methods)) {
            $methods = [$methods];
        }
        return \array_values(\array_map(\strtoupper(...), $methods));
    }
}
