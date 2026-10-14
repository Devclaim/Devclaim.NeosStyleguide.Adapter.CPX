<?php

declare(strict_types=1);

namespace Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue;

use Neos\Flow\Annotations as Flow;
use PackageFactory\ComponentEngine\ComponentInterface;

/**
 * An entry of the styleguide: a component with a <Component>.styleguide.yaml next to it
 */
#[Flow\Proxy(false)]
final readonly class Entry
{
    /**
     * @param class-string<ComponentInterface> $componentClass
     * @param array<string,mixed> $configuration the YAML file
     */
    public function __construct(
        /** "Vendor.Shared.Block.Button": the package key and the path below Components, with dots */
        public string $id,
        public string $title,
        public string $level,
        public string $description,
        public string $componentClass,
        /** the YAML file, relative to the root of the project */
        public string $source,
        public array $configuration,
    ) {
    }

    /**
     * @return array<string,mixed> the default props
     */
    public function props(): array
    {
        $props = $this->configuration['props'] ?? [];

        return is_array($props) ? $props : [];
    }

    /**
     * @return array<string,array<string,mixed>> the prop sets: alternative props, by id
     */
    public function propSets(): array
    {
        $propSets = [];
        $configured = $this->configuration['propSets'] ?? null;
        foreach (is_array($configured) ? $configured : [] as $id => $propSet) {
            if (is_array($propSet)) {
                // Monocle writes the props of a prop set directly or below "props"
                $propSets[(string)$id] = is_array($propSet['props'] ?? null) ? $propSet['props'] : $propSet;
            }
        }

        return $propSets;
    }

    /**
     * @return array<string,array{title:string,props:array<string,mixed>}> the use cases, by id
     */
    public function useCases(): array
    {
        $useCases = [];
        $configured = $this->configuration['useCases'] ?? null;
        foreach (is_array($configured) ? $configured : [] as $id => $useCase) {
            if (is_array($useCase)) {
                $useCases[(string)$id] = [
                    'title' => is_string($useCase['title'] ?? null) ? $useCase['title'] : (string)$id,
                    'props' => is_array($useCase['props'] ?? null) ? $useCase['props'] : [],
                ];
            }
        }

        return $useCases;
    }
}
