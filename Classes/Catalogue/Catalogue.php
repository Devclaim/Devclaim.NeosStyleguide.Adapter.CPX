<?php

declare(strict_types=1);

namespace Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\FlowPackageInterface;
use Neos\Flow\Package\PackageManager;
use PackageFactory\ComponentEngine\ComponentInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The entries of the styleguide: every component with a <Component>.styleguide.yaml next to it, in the Components
 * folder of a package (the format of Sitegeist.Monocle: title, description, group, container, props, propSets,
 * useCases, hidden). Hidden files are no entries, but give their defaults to the components that use them.
 */
#[Flow\Scope('singleton')]
class Catalogue
{
    public const PACKAGE_KEY = 'Devclaim.NeosStyleguide.Adapter.CPX';
    private const OTHER_LEVEL = 'other';

    #[Flow\Inject]
    protected PackageManager $packageManager;

    #[Flow\Inject(name: 'Neos.Flow:SystemLogger')]
    protected LoggerInterface $logger;

    /**
     * @var array<string,bool|null>
     */
    #[Flow\InjectConfiguration(path: 'packages', package: self::PACKAGE_KEY)]
    protected array $packages = [];

    /**
     * @var array<string,array{label?:string,position?:int,match?:string}|null>
     */
    #[Flow\InjectConfiguration(path: 'levels', package: self::PACKAGE_KEY)]
    protected array $levelSettings = [];

    /**
     * @var list<Entry>|null
     */
    private ?array $entries = null;

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $configurations = [];

    /**
     * @return list<Entry> by level and title
     */
    public function entries(): array
    {
        if ($this->entries === null) {
            $entries = [];
            foreach ($this->componentPackages() as $package) {
                foreach ($this->styleguideFiles($package) as $file) {
                    try {
                        $entry = $this->entryOf($package, $file);
                    } catch (\Throwable $error) {
                        $this->logger->warning(sprintf('Styleguide: %s left out: %s', $file, $error->getMessage()));
                        continue;
                    }
                    if ($entry !== null) {
                        $entries[] = $entry;
                    }
                }
            }
            $positions = array_column($this->configuredLevels(), 'position', 'id');
            usort(
                $entries,
                fn (Entry $a, Entry $b) =>
                    ($positions[$a->level] ?? PHP_INT_MAX) <=> ($positions[$b->level] ?? PHP_INT_MAX)
                    ?: strnatcasecmp($a->title, $b->title)
            );
            $this->entries = $entries;
        }

        return $this->entries;
    }

    public function find(string $id): ?Entry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The levels of the navigation, by position; "other" only if an entry is on it
     *
     * @return list<array{id:string,label:string,position:int}>
     */
    public function levels(): array
    {
        $levels = $this->configuredLevels();
        if (in_array(self::OTHER_LEVEL, array_map(fn (Entry $entry) => $entry->level, $this->entries()), true)) {
            $levels[] = ['id' => self::OTHER_LEVEL, 'label' => 'Weitere', 'position' => PHP_INT_MAX];
        }

        return $levels;
    }

    /**
     * @return list<array{id:string,label:string,position:int}>
     */
    private function configuredLevels(): array
    {
        $levels = [];
        foreach (array_filter($this->levelSettings) as $id => $level) {
            $levels[] = [
                'id' => (string)$id,
                'label' => $level['label'] ?? (string)$id,
                'position' => (int)($level['position'] ?? 0),
            ];
        }
        usort($levels, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $levels;
    }

    /**
     * The YAML file next to a component (also of hidden ones and of components of other packages); [] without one
     *
     * @param class-string $componentClass
     * @return array<string,mixed>
     */
    public function configurationOf(string $componentClass): array
    {
        if (!array_key_exists($componentClass, $this->configurations)) {
            $classFile = (new \ReflectionClass($componentClass))->getFileName();
            $file = $classFile ? substr($classFile, 0, -strlen('.php')) . '.styleguide.yaml' : '';
            $this->configurations[$componentClass] = is_file($file) ? $this->parse($file) : [];
        }

        return $this->configurations[$componentClass];
    }

    /**
     * The class of a component, by its module id ("Vendor.Shared/Block/Button/Button.cpx") or the path of its YAML
     * file below Components
     *
     * @return class-string
     */
    public static function classOf(string $moduleId): string
    {
        $moduleId = (string)preg_replace('/\.(cpx|styleguide\.yaml)$/', '', $moduleId);
        if (!str_contains($moduleId, '/')) {
            throw new \InvalidArgumentException(sprintf('Invalid component "%s"', $moduleId), 1760630101);
        }
        [$packageKey, $path] = explode('/', $moduleId, 2);
        $className = str_replace('.', '\\', $packageKey) . '\\Components\\' . str_replace('/', '\\', $path);
        if (!class_exists($className)) {
            throw new \InvalidArgumentException(sprintf('No class "%s" for "%s"', $className, $moduleId), 1760630102);
        }

        return $className;
    }

    /**
     * @return list<FlowPackageInterface>
     */
    private function componentPackages(): array
    {
        $selected = array_keys(array_filter($this->packages));
        $packages = [];
        foreach ($this->packageManager->getFlowPackages() as $package) {
            if ($selected !== [] && !in_array($package->getPackageKey(), $selected, true)) {
                continue;
            }
            if (is_dir($package->getPackagePath() . 'Components')) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * @return list<string>
     */
    private function styleguideFiles(FlowPackageInterface $package): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($package->getPackagePath() . 'Components', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.styleguide.yaml')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function entryOf(FlowPackageInterface $package, string $file): ?Entry
    {
        $configuration = $this->parse($file);
        if (($configuration['hidden'] ?? false) === true) {
            return null;
        }
        $path = substr($file, strlen($package->getPackagePath() . 'Components/'), -strlen('.styleguide.yaml'));
        $componentClass = self::classOf($package->getPackageKey() . '/' . $path);
        if (!is_subclass_of($componentClass, ComponentInterface::class)) {
            throw new \InvalidArgumentException(sprintf('%s is no component', $componentClass), 1760630103);
        }
        $segments = explode('/', $path);
        // Block/Button/Button: the folder carries the name once
        if (count($segments) > 1 && $segments[count($segments) - 1] === $segments[count($segments) - 2]) {
            array_pop($segments);
        }
        $group = is_string($configuration['group'] ?? null) ? $configuration['group'] : null;
        $root = (string)constant('FLOW_PATH_ROOT');

        return new Entry(
            // the interface keeps the id in one path segment of its URL
            id: $package->getPackageKey() . '.' . implode('.', $segments),
            title: is_string($configuration['title'] ?? null) ? $configuration['title'] : basename($path),
            level: $this->levelOf($configuration, $group ?? $path),
            description: is_string($configuration['description'] ?? null) ? $configuration['description'] : '',
            componentClass: $componentClass,
            source: str_starts_with($file, $root) ? substr($file, strlen($root)) : $file,
            configuration: $configuration,
        );
    }

    /**
     * @param array<string,mixed> $configuration
     */
    private function levelOf(array $configuration, string $subject): string
    {
        $levels = array_filter($this->levelSettings);
        if (is_string($configuration['level'] ?? null) && isset($levels[$configuration['level']])) {
            return $configuration['level'];
        }
        uasort($levels, fn (array $a, array $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        foreach ($levels as $id => $level) {
            $pattern = isset($level['match']) ? '#' . str_replace('#', '\#', $level['match']) . '#' : null;
            if ($pattern !== null && preg_match($pattern, $subject) === 1) {
                return (string)$id;
            }
        }

        return self::OTHER_LEVEL;
    }

    /**
     * @return array<string,mixed>
     */
    private function parse(string $file): array
    {
        $configuration = Yaml::parseFile($file);

        return is_array($configuration) ? $configuration : [];
    }
}
