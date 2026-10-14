# Devclaim.NeosStyleguide.Adapter.CPX

The CPX adapter of [Devclaim.NeosStyleguide](https://github.com/Devclaim/Devclaim.NeosStyleguide), the styleguide in the
Neos content module: it answers the styleguide protocol for components of the PackageFactory component engine (CPX),
inside Neos. The components describe themselves in a `<Component>.styleguide.yaml` next to them, in the format of
Sitegeist.Monocle; the design tokens are the `@theme` blocks of the site's stylesheets (Tailwind).

- the protocol under `/neos/styleguide/api/v1`: manifest, entries with their prop editors, tokens, validation of token
    overrides
- the preview and the gallery under `/neos/styleguide/preview`, in the stylesheets and scripts of the website and the
    tokens of the selected site, with the page grid
- for logged-in editors only (the Neos backend session), `401` without one

## Installation

```bash
composer require devclaim/neos-styleguide-adapter-cpx
```

Requires the core package (`devclaim/neos-styleguide`, with the `DesignTokensMixin` on the site node type) and the
component engine (`packagefactory/neos-component-engine`).

## Setup

```yaml
Devclaim:
  NeosStyleguide:
    # the stylesheets with the @theme blocks of the design tokens, read as source (core)
    theme:
      stylesheets:
        'Vendor.Shared:Styles': 'resource://Vendor.Shared/Private/Styles/Styles.css'
    Adapter:
      CPX:
        # the packages whose components are in the styleguide (empty: every package with a Components folder)
        packages:
          'Vendor.Shared': true
        # the levels of the navigation: by "level" in the YAML file, else the first "match" on its "group" or path
        levels:
          block: { label: 'Block', position: 200, match: '^Block' }
        preview:
          # the built CSS and JavaScript of the website
          stylesheets:
            'Vendor.Shared:Styles': 'resource://Vendor.Shared/Public/Build/Styles/main.min.css'
          scripts:
            'Vendor.Shared:JavaScript': 'resource://Vendor.Shared/Public/Build/JavaScript/main.min.js'
          # the page grid (the format of Monocle's grids), one entry per media query
          grid:
            xl: { mediaQuery: '(min-width:1280px)', columns: 12, gap: 64px, gutter: '0 64px', maxWidth: 1920px }
```

The pages of the website apply the tokens a site saves: in the `<head>`, after the stylesheet,

```php
$theme->styleElement($siteNode->getProperty('designTokens'))   // Devclaim\NeosStyleguide\Tokens\Theme
```

(Fusion: `NeosStyleguide.Tokens.styleElement(...)`), and the uploaded fonts with `FontLibrary::css()`.

## An entry

`Button.php` (built from `Button.cpx`) → `Button.styleguide.yaml`:

```yaml
title: 'Button'
group: UI                     # the level by "match" (or level: ui)
container:                    # what the preview puts around it, e.g. the page grid
  __type: 'Vendor.Shared/Layout/AtomContainer/AtomContainer.cpx'
props:                        # the arguments of Button::create()
  content: 'Lorem ipsum'
  variant:
    __type: 'Vendor.Shared/Block/Button/ButtonVariant.cpx'   # an enum
    value: 'regular'
useCases:
  solid:
    title: 'Solid button'
    props: { variant: { __type: 'Vendor.Shared/Block/Button/ButtonVariant.cpx', value: 'solid' } }
```

- `__type` is a component (with the defaults of its own YAML file; `__useCase` / `__propSet` pick one of them), a
    struct (`create()`) or an enum (`value`); a list is a slot of several components; a string with markup in a slot is
    HTML.
- Text, numbers, booleans and enums with a default get an editor in the props inspector.
- `hidden: true`: no entry (e.g. a container), but its defaults apply where it is used.
- `page: true` and `gallery: false`: a whole page, shown alone and scrolled inside its frame.

## License

GPL-3.0-or-later
