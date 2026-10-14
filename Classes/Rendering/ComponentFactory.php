<?php

declare(strict_types=1);

namespace Devclaim\NeosStyleguide\Adapter\Cpx\Rendering;

use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Catalogue;
use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Entry;
use Neos\Flow\Annotations as Flow;
use PackageFactory\ComponentEngine\ComponentInterface;
use PackageFactory\ComponentEngine\ComponentList;
use PackageFactory\ComponentEngine\ComponentListInterface;
use PackageFactory\ComponentEngine\StringComponent;

/**
 * Builds a component from the props of the styleguide, the way Sitegeist.Monocle does: the props are the arguments of
 * its static create() method. A value with "__type" (a module id, "Vendor.Shared/Block/Copy/Copy.cpx") is a component
 * (with the defaults of its own YAML file, "__useCase" and "__propSet" pick one of its), a struct (create()) or an enum
 * ("value"); a list is a slot of several components; a string with markup in a slot is HTML.
 */
#[Flow\Scope('singleton')]
class ComponentFactory
{
    #[Flow\Inject]
    protected Catalogue $catalogue;

    /**
     * The order of the props: defaults, prop set, use case, the props changed in the inspector
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public function propsOf(Entry $entry, ?string $propSet, ?string $useCase, array $overrides): array
    {
        return array_replace(
            $entry->props(),
            $propSet !== null ? $entry->propSets()[$propSet] ?? [] : [],
            $useCase !== null ? $entry->useCases()[$useCase]['props'] ?? [] : [],
            $overrides
        );
    }

    /**
     * The entry with its props, in its container (the "container" of its YAML file, e.g. the page grid)
     *
     * @param array<string,mixed> $props
     */
    public function forEntry(Entry $entry, array $props): ComponentInterface
    {
        $component = $this->create($entry->componentClass, $props);
        $container = $entry->configuration['container'] ?? null;
        if (is_array($container) && is_string($container['__type'] ?? null)) {
            return $this->fromConfiguration([...$container, 'content' => $component]);
        }

        return $component;
    }

    /**
     * @param class-string $className
     * @param array<string,mixed> $props
     */
    private function create(string $className, array $props): ComponentInterface
    {
        $component = $className::create(...$this->arguments($className, $props));
        if (!$component instanceof ComponentInterface) {
            throw new \InvalidArgumentException(sprintf('%s::create() returns no component', $className), 1760630201);
        }

        return $component;
    }

    /**
     * A value with "__type": a component with the defaults of its YAML file, a struct or an enum
     *
     * @param array<string,mixed> $configuration
     */
    private function fromConfiguration(array $configuration): mixed
    {
        $className = Catalogue::classOf((string)$configuration['__type']);
        if (enum_exists($className) && is_subclass_of($className, \BackedEnum::class)) {
            return $className::from($configuration['value'] ?? '');
        }
        $props = array_filter($configuration, fn ($key) => !str_starts_with((string)$key, '__'), ARRAY_FILTER_USE_KEY);
        if (is_subclass_of($className, ComponentInterface::class)) {
            $styleguide = $this->catalogue->configurationOf($className);
            $defaults = is_array($styleguide['props'] ?? null) ? $styleguide['props'] : [];
            $useCase = $configuration['__useCase'] ?? null;
            if (is_string($useCase) && is_array($styleguide['useCases'][$useCase]['props'] ?? null)) {
                $defaults = array_replace($defaults, $styleguide['useCases'][$useCase]['props']);
            }
            $propSet = $configuration['__propSet'] ?? null;
            if (is_string($propSet) && is_array($styleguide['propSets'][$propSet] ?? null)) {
                $set = $styleguide['propSets'][$propSet];
                $defaults = array_replace($defaults, is_array($set['props'] ?? null) ? $set['props'] : $set);
            }

            return $this->create($className, array_replace($defaults, $props));
        }
        if (method_exists($className, 'create')) {
            return $className::create(...$this->arguments($className, $props));
        }
        throw new \InvalidArgumentException(sprintf('%s is no component, struct or enum', $className), 1760630202);
    }

    /**
     * The arguments of create() from the props; missing ones get their default or null
     *
     * @param class-string $className
     * @param array<string,mixed> $props
     * @return array<string,mixed>
     */
    private function arguments(string $className, array $props): array
    {
        $arguments = [];
        foreach ((new \ReflectionMethod($className, 'create'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $props)) {
                $arguments[$name] = $this->value($props[$name], $parameter);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
            } else {
                $arguments[$name] = null;
            }
            unset($props[$name]);
        }
        if ($props !== []) {
            throw new \InvalidArgumentException(
                sprintf('Unknown props "%s" for %s', implode('", "', array_keys($props)), $className),
                1760630203
            );
        }

        return $arguments;
    }

    private function value(mixed $value, ?\ReflectionParameter $parameter = null): mixed
    {
        if (is_array($value)) {
            if (is_string($value['__type'] ?? null)) {
                return $this->fromConfiguration($value);
            }
            if (array_is_list($value)) {
                return $parameter !== null && self::acceptsComponent($parameter)
                    ? $this->componentList($value)
                    : array_map(fn (mixed $item) => $this->value($item), $value);
            }
            throw new \InvalidArgumentException('A map without "__type" is no component, struct or enum', 1760630204);
        }
        if ($parameter === null) {
            return $value;
        }
        $types = self::namedTypes($parameter);
        // a select box of the interface cannot hold null: "" stands for it
        if ($value === '' && $parameter->allowsNull() && !in_array('string', $types, true)) {
            return null;
        }
        foreach ($types as $type) {
            $isEnum = enum_exists($type) && is_subclass_of($type, \BackedEnum::class);
            if ($isEnum && ($value === null || is_int($value) || is_string($value))) {
                return $value === null ? null : $type::from($value);
            }
        }
        if (is_string($value) && self::acceptsComponent($parameter) && self::isHtml($value)) {
            return StringComponent::fromHtmlString($value);
        }

        return $value;
    }

    /**
     * @param list<mixed> $values
     * @return ComponentList<ComponentInterface>
     */
    private function componentList(array $values): ComponentList
    {
        $items = [];
        foreach ($values as $value) {
            $item = $this->value($value);
            if (is_string($item)) {
                $item = self::isHtml($item)
                    ? StringComponent::fromHtmlString($item)
                    : StringComponent::fromString($item);
            }
            if ($item !== null && !$item instanceof ComponentInterface) {
                throw new \InvalidArgumentException('A list of a slot may only hold components and text', 1760630205);
            }
            $items[] = $item;
        }

        return ComponentList::list(...$items);
    }

    /**
     * @return list<string>
     */
    public static function namedTypes(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];

        return array_values(array_map(
            fn (\ReflectionNamedType $named) => $named->getName(),
            array_filter($types, fn ($named) => $named instanceof \ReflectionNamedType)
        ));
    }

    private static function acceptsComponent(\ReflectionParameter $parameter): bool
    {
        return array_intersect(
            self::namedTypes($parameter),
            [ComponentInterface::class, ComponentListInterface::class]
        ) !== [];
    }

    private static function isHtml(string $value): bool
    {
        return preg_match('/<[^>]+>/', $value) === 1;
    }
}
