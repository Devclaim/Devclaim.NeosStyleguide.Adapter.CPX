<?php

declare(strict_types=1);

namespace Devclaim\NeosStyleguide\Adapter\Cpx\Rendering;

use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Catalogue;
use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Entry;
use Devclaim\NeosStyleguide\Tokens\Theme;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\ResourceManager;
use PackageFactory\ComponentEngine\Util;
use Psr\Log\LoggerInterface;

/**
 * The preview of the styleguide (PROTOCOL.md "Preview"): the entries in the stylesheets and scripts of the website
 * (setting preview), with the design tokens of the site; each entry in data-styleguide-entry, all in
 * data-styleguide-root. A plain page: the interface puts its draft tokens into it and measures it itself.
 */
#[Flow\Scope('singleton')]
class PreviewPage
{
    #[Flow\Inject]
    protected ComponentFactory $componentFactory;

    #[Flow\Inject]
    protected Theme $theme;

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    #[Flow\Inject(name: 'Neos.Flow:SystemLogger')]
    protected LoggerInterface $logger;

    /**
     * @var array{stylesheets?:array<string,string|null>,scripts?:array<string,string|null>,grid?:array<string,array<string,mixed>|null>}
     */
    #[Flow\InjectConfiguration(path: 'preview', package: Catalogue::PACKAGE_KEY)]
    protected array $settings = [];

    /**
     * @param list<array{entry:Entry,props:array<string,mixed>}> $items
     */
    public function render(string $title, array $items, ?string $designTokens, string $language): string
    {
        $head = '';
        foreach (array_filter($this->settings['stylesheets'] ?? []) as $path) {
            $head .= '<link rel="stylesheet" href="' . Util::escapeAttributeValue($this->publicUri($path)) . '">';
        }
        foreach (array_filter($this->settings['scripts'] ?? []) as $path) {
            $head .= '<script defer src="' . Util::escapeAttributeValue($this->publicUri($path)) . '"></script>';
        }
        // the saved tokens of the site, as on its pages (the styleguide puts its draft after them)
        $head .= $this->theme->styleElement($designTokens);
        [$gridStyle, $grid] = $this->grid();

        $body = '';
        foreach ($items as $item) {
            $body .= '<div data-styleguide-entry="' . Util::escapeAttributeValue($item['entry']->id) . '">'
                . $this->renderEntry($item['entry'], $item['props'])
                . '</div>';
        }

        return '<!DOCTYPE html><html lang="' . Util::escapeAttributeValue($language) . '"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width">'
            . '<title>' . htmlspecialchars($title) . '</title>'
            . $head . $gridStyle
            . '</head><body><div data-styleguide-root>' . $body . '</div>' . $grid . '</body></html>';
    }

    /**
     * @param array<string,mixed> $props
     */
    private function renderEntry(Entry $entry, array $props): string
    {
        try {
            return $this->componentFactory->forEntry($entry, $props)->render();
        } catch (\Throwable $error) {
            // one broken entry does not break the gallery
            $this->logger->warning(sprintf('Styleguide: %s cannot be rendered: %s', $entry->id, $error->getMessage()));

            return '<pre style="margin:16px;padding:16px;white-space:pre-wrap;color:#b00020;background:#fdecee;'
                . 'font:13px/1.4 monospace">' . htmlspecialchars($error->getMessage()) . '</pre>';
        }
    }

    /**
     * The page grid of the website (setting preview.grid, the format of Sitegeist.Monocle's grids), while <html> has
     * data-styleguide-grid
     *
     * @return array{0:string,1:string} the style and the element
     */
    private function grid(): array
    {
        $breakpoints = array_filter($this->settings['grid'] ?? []);
        if ($breakpoints === []) {
            return ['', ''];
        }
        $maxColumns = max(array_map(fn (array $grid) => (int)($grid['columns'] ?? 12), $breakpoints));
        $css = '[data-styleguide-page-grid]{position:fixed;inset:0;z-index:9999;pointer-events:none;display:none}'
            . '[data-styleguide-grid] [data-styleguide-page-grid]{display:block}'
            . '[data-styleguide-page-grid]>div{display:grid;height:100%;margin:0 auto;box-sizing:border-box}'
            . '[data-styleguide-page-grid] span{background:rgba(0,173,238,.12);border:solid rgba(0,173,238,.55);'
            . 'border-width:0 1px}';
        foreach ($breakpoints as $grid) {
            $columns = (int)($grid['columns'] ?? 12);
            $rules = '[data-styleguide-page-grid]>div{grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));'
                . 'column-gap:' . self::cssValue($grid['gap'] ?? '0') . ';'
                . 'padding:' . self::cssValue($grid['gutter'] ?? '0') . ';'
                . 'max-width:' . self::cssValue($grid['maxWidth'] ?? 'none') . '}'
                . '[data-styleguide-page-grid] span{display:block}'
                . '[data-styleguide-page-grid] span:nth-child(n+' . ($columns + 1) . '){display:none}';
            $mediaQuery = $grid['mediaQuery'] ?? null;
            $css .= is_string($mediaQuery) ? '@media ' . self::cssValue($mediaQuery) . '{' . $rules . '}' : $rules;
        }

        return [
            '<style>' . $css . '</style>',
            '<div data-styleguide-page-grid aria-hidden="true"><div>'
                . str_repeat('<span></span>', $maxColumns) . '</div></div>',
        ];
    }

    private function publicUri(string $path): string
    {
        return $this->resourceManager->getPublicPackageResourceUriByPath($path);
    }

    /**
     * A value of the settings in the style element: nothing that leaves the declaration
     */
    private static function cssValue(mixed $value): string
    {
        return (string)preg_replace('/[;{}<>]/', '', (string)$value);
    }
}
