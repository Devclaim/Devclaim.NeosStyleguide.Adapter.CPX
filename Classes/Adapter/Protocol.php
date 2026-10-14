<?php

declare(strict_types=1);

namespace Devclaim\NeosStyleguide\Adapter\Cpx\Adapter;

use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Catalogue;
use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Entry;
use Devclaim\NeosStyleguide\Adapter\Cpx\Rendering\ComponentFactory;
use Devclaim\NeosStyleguide\Tokens\Theme;
use Neos\Flow\Annotations as Flow;

/**
 * The answers of the styleguide protocol, version 2 (Devclaim.NeosStyleguide PROTOCOL.md): the catalogue and the
 * design tokens of the theme (Devclaim.NeosStyleguide.theme.stylesheets) in its format
 */
#[Flow\Scope('singleton')]
class Protocol
{
    public const VERSION = 2;

    /** the kinds of tokens, the navigation of the styleguide, in this order */
    private const KINDS = [
        'colors' => 'Farben',
        'typography' => 'Schrift',
        'spacing' => 'Abstände und Größen',
        'shadows' => 'Schatten',
        'ratios' => 'Formate',
        'grid' => 'Raster',
    ];

    #[Flow\Inject]
    protected Catalogue $catalogue;

    #[Flow\Inject]
    protected Theme $theme;

    #[Flow\InjectConfiguration(path: 'name', package: Catalogue::PACKAGE_KEY)]
    protected string $name = 'CPX';

    /**
     * @var array<string,array{label:string,width:int,height:int}|null>
     */
    #[Flow\InjectConfiguration(path: 'viewports', package: Catalogue::PACKAGE_KEY)]
    protected array $viewports = [];

    /**
     * @var array<string,mixed>
     */
    #[Flow\InjectConfiguration(path: 'preview.grid', package: Catalogue::PACKAGE_KEY)]
    protected array $grid = [];

    /**
     * @return array<string,mixed>
     */
    public function manifest(string $previewUri): array
    {
        $viewports = [];
        foreach (array_filter($this->viewports) as $id => $viewport) {
            $viewports[] = ['id' => (string)$id, ...$viewport];
        }

        return [
            'protocol' => self::VERSION,
            'name' => $this->name,
            'levels' => $this->catalogue->levels(),
            'viewports' => $viewports,
            'preview' => $previewUri . '?entry={entry}&site={site}',
            'gallery' => $previewUri . '?entries={entries}&site={site}',
            'features' => ['grid' => array_filter($this->grid) !== []],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function entries(): array
    {
        return array_map($this->summary(...), $this->catalogue->entries());
    }

    /**
     * The entry with its props and their editors: the props of create() with a default in the YAML file that can be
     * edited (text, numbers, booleans, enums)
     *
     * @return array<string,mixed>
     */
    public function details(Entry $entry): array
    {
        $editors = $this->editors($entry);
        $editable = fn (array $props): array => array_map(
            self::editorValue(...),
            array_intersect_key($props, $editors)
        );
        $props = [];
        foreach ($editable($entry->props()) as $name => $value) {
            $props[] = ['name' => $name, 'value' => $value, 'editor' => $editors[$name]];
        }
        $propSets = [];
        foreach ($entry->propSets() as $id => $set) {
            $propSets[] = ['id' => $id, 'title' => $id, 'props' => (object)$editable($set)];
        }
        $useCases = [];
        foreach ($entry->useCases() as $id => $useCase) {
            $useCases[] = ['id' => $id, 'title' => $useCase['title'], 'props' => (object)$editable($useCase['props'])];
        }

        return [...$this->summary($entry), 'props' => $props, 'propSets' => $propSets, 'useCases' => $useCases];
    }

    /**
     * @return array<string,mixed>
     */
    public function tokens(): array
    {
        $tokens = $this->theme->tokens();
        $groups = [];
        $list = [];
        foreach ($tokens->defaults as $token) {
            if ($token->group !== '') {
                $id = self::slug($token->group);
                $names = array_map(
                    fn ($other) => $other->name,
                    array_filter($tokens->defaults, fn ($other) => $other->group === $token->group)
                );
                $groups[$id] ??= [
                    'id' => $id,
                    'label' => $token->group,
                    'description' => $token->groupDescription,
                    'kind' => self::kindOf($names),
                ];
            } else {
                // a token without a "Group:" comment: in the group of its kind
                $kind = self::kindOf([$token->name]);
                $id = $kind;
                $groups[$id] ??= ['id' => $id, 'label' => self::KINDS[$kind], 'description' => '', 'kind' => $kind];
            }
            $list[] = [
                'name' => $token->name,
                'value' => $token->value,
                'group' => $id,
                'type' => self::tokenType($token->name),
                'description' => $token->description,
                'editable' => $tokens->isEditable($token->name),
            ];
        }
        $used = array_unique(array_column($groups, 'kind'));
        $kinds = [];
        foreach (self::KINDS as $id => $label) {
            if (in_array($id, $used, true)) {
                $kinds[] = ['id' => $id, 'label' => $label];
            }
        }

        return ['protocol' => self::VERSION, 'kinds' => $kinds, 'groups' => array_values($groups), 'tokens' => $list];
    }

    /**
     * Which overrides a site may use, with the rules the pages apply (ThemeTokens::rejection())
     *
     * @param array<mixed> $overrides
     * @return array<string,mixed>
     */
    public function validate(array $overrides): array
    {
        $tokens = $this->theme->tokens();
        $valid = [];
        $invalid = [];
        foreach ($overrides as $name => $raw) {
            $value = is_string($raw) ? trim($raw) : '';
            $reason = is_string($raw) ? $tokens->rejection((string)$name, $value) : 'value not allowed';
            if ($reason !== null) {
                $invalid[] = [
                    'name' => (string)$name,
                    'value' => is_scalar($raw) ? (string)$raw : '',
                    'reason' => $reason,
                ];
            } else {
                $valid[(string)$name] = $value;
            }
        }

        return ['protocol' => self::VERSION, 'valid' => (object)$valid, 'invalid' => $invalid];
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'level' => $entry->level,
            'description' => $entry->description,
            'useCases' => array_map(
                fn (string $id, array $useCase) => ['id' => $id, 'title' => $useCase['title']],
                array_keys($entry->useCases()),
                array_values($entry->useCases())
            ),
            'propSets' => array_map(
                fn ($id) => ['id' => (string)$id, 'title' => (string)$id],
                array_keys($entry->propSets())
            ),
            'source' => $entry->source,
            'gallery' => ($entry->configuration['gallery'] ?? true) !== false,
            'page' => ($entry->configuration['page'] ?? false) === true,
        ];
    }

    /**
     * The editors of the props with a default, by the types of create()
     *
     * @return array<string,array<string,mixed>>
     */
    private function editors(Entry $entry): array
    {
        $defaults = $entry->props();
        $editors = [];
        foreach ((new \ReflectionMethod($entry->componentClass, 'create'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (!array_key_exists($name, $defaults)) {
                continue;
            }
            $editor = $this->editorOf($parameter, $defaults[$name]);
            if ($editor !== null) {
                $editors[$name] = $editor;
            }
        }

        return $editors;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function editorOf(\ReflectionParameter $parameter, mixed $default): ?array
    {
        $types = ComponentFactory::namedTypes($parameter);
        foreach ($types as $type) {
            if (enum_exists($type) && is_subclass_of($type, \BackedEnum::class)) {
                $options = array_map(
                    fn (\BackedEnum $case) => ['value' => (string)$case->value, 'label' => (string)$case->value],
                    $type::cases()
                );
                if ($parameter->allowsNull()) {
                    array_unshift($options, ['value' => '', 'label' => '–']);
                }

                return ['type' => 'select', 'options' => $options];
            }
        }
        if (is_bool($default) && in_array('bool', $types, true)) {
            return ['type' => 'checkbox'];
        }
        if ((is_int($default) || is_float($default)) && array_intersect($types, ['int', 'float']) !== []) {
            return ['type' => 'number'];
        }
        if (is_string($default) && in_array('string', $types, true)) {
            // markup and long texts
            $long = str_contains($default, '<') || str_contains($default, "\n") || mb_strlen($default) > 60;

            return ['type' => $long ? 'textarea' : 'text'];
        }

        return null;
    }

    /**
     * The value of a prop in an editor: the value of an enum, "" for null
     */
    private static function editorValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }

        return $value ?? '';
    }

    /**
     * The kind of a token by its name, for the editor of the interface
     */
    private static function tokenType(string $name): string
    {
        $types = [
            '/^--color-/' => 'color',
            '/^--font-/' => 'font',
            '/^--(text-size|leading|paragraph-spacing|spacing|max-width)-/' => 'size',
            '/^--shadow-/' => 'shadow',
            '/^--aspect-/' => 'ratio',
            '/^--breakpoint-/' => 'breakpoint',
        ];
        foreach ($types as $pattern => $type) {
            if (preg_match($pattern, $name) === 1) {
                return $type;
            }
        }

        return 'other';
    }

    /**
     * The kind of a group, by the names of its tokens
     *
     * @param array<string> $names
     */
    private static function kindOf(array $names): string
    {
        $any = fn (string $pattern): bool =>
            array_filter($names, fn ($name) => preg_match($pattern, $name) === 1) !== [];
        if (array_filter($names, fn ($name) => !str_starts_with($name, '--color-')) === []) {
            return 'colors';
        }
        if ($any('/^--(font|text-size|leading|paragraph-spacing)-/')) {
            return 'typography';
        }
        if ($any('/^--shadow-/')) {
            return 'shadows';
        }
        if ($any('/^--aspect-/')) {
            return 'ratios';
        }
        if ($any('/^--(breakpoint|container|grid|max-width-page)-/')) {
            return 'grid';
        }

        return 'spacing';
    }

    private static function slug(string $label): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-') ?: 'other';
    }
}
