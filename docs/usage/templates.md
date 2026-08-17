# Template Integration

This guide covers the frontend render contract: where to put templates, how the holder chain composes, what classes get emitted, and how a theme overrides the module defaults.

## Page template

Page types with `GridPageExtension` expose `$UseGrid` and `$Sections`. The canonical pattern is:

```silverstripe
<% if $UseGrid %>
    <% loop $Sections %>$Me<% end_loop %>
<% else %>
    $Content
<% end_if %>
```

The frontend template renders the raw stored `$UseGrid` field unconditionally — there is no separate render-time gate. The stored value is seeded from `use_grid_by_default` when a page is created (see [Default editor on new pages](#default-editor-on-new-pages)), so with the default (`true`) the `<% else %>` branch never fires and you can simplify to `<% loop $Sections %>$Me<% end_loop %>`. The per-page editor toggle (see [below](#per-page-editor-toggle)) only governs which editor the CMS shows; it does not change how the template reads `$UseGrid`.

## Multi-zone pages

A page gets multiple zones by declaring one `GridEditorField` per zone, each with a different zone string:

```php
$fields->addFieldToTab('Root.Main', GridEditorField::create('GridEditorMain', (int) $this->ID, 'main'));
$fields->addFieldToTab('Root.Main', GridEditorField::create('GridEditorSidebar', (int) $this->ID, 'sidebar'));
```

`Sections()` returns every section on the page regardless of zone, so the template picks a zone by filtering on the `Zone` field:

```silverstripe
<div class="main">
    <% loop $Sections.Filter('Zone', 'main') %>$Me<% end_loop %>
</div>
<aside>
    <% loop $Sections.Filter('Zone', 'sidebar') %>$Me<% end_loop %>
</aside>
```

Sort values are independent per zone, so reordering one zone never renumbers another. `.docker/app/src/MultiZonePage.php` in this repository is a working two-zone page type used by the E2E suite.

## Per-page editor toggle

To let CMS users switch between the grid editor and the Content `HTMLEditorField` on a per-page basis, opt in on the page type:

```yaml
App\Pages\ArticlePage:
  enable_editor_toggle: true
```

With the toggle enabled a **"Use grid on this page"** checkbox appears in the CMS form and the editor shown reflects the stored `UseGrid` value, which CMS users can flip per page. With the toggle disabled the checkbox is hidden and the CMS always shows the grid editor — but the stored `UseGrid` value (seeded from `use_grid_by_default`) is unchanged, and the template still renders it. The toggle only affects the CMS editor-selection logic, never how the frontend reads `$UseGrid`.

## Default editor on new pages

The grid is enabled by default on newly created pages. Override per page type in YAML:

```yaml
App\Pages\ArticlePage:
  use_grid_by_default: true    # default — grid editor on new pages

App\Pages\JobPage:
  use_grid_by_default: false   # content editor on new pages (template renders $Content)
```

`use_grid_by_default` seeds the stored `UseGrid` field on every newly created page, regardless of the toggle. Because the frontend template renders the raw `$UseGrid` field, setting `use_grid_by_default: false` **without** the toggle stores `false` on new pages, so the template's `<% else %>` branch fires and `$Content` renders instead of the grid. Combine it with `enable_editor_toggle: true` when you also want CMS users to flip the value per page.

## The holder chain

Each element renders in two passes:

1. **Holder** — outer wrapper: `{ClassName}_holder.ss`. Falls back to `GridElement_holder.ss` (the module ships one that just emits `$Element`).
2. **Inner** — content: `{ClassName}.ss`.

`GridElement::forTemplate()` renders `{ClassName}_holder.ss`, which references `$Element`, which in turn renders `{ClassName}.ss`. The split lets the holder contribute framework classes (e.g. a Bootstrap `row`) while the inner template renders content.

The module ships holders for `Section`, `Row`, and `Column`. Content elements typically reuse the default `GridElement_holder.ss` (it just emits `$Element`) and render everything inside `{ClassName}.ss`.

### What the default holders produce

The shipped templates (simplified — the actual files carry conditional class attributes):

```silverstripe
<!-- Section_holder.ss -->
<section class="$HolderClasses.ATT" data-element="$SimpleClassName.LowerCase" id="$Anchor">
    <div class="$ContainerClasses">$Element</div>
</section>

<!-- Row_holder.ss -->
<div class="$HolderClasses.ATT" data-element="$SimpleClassName.LowerCase" id="$Anchor">$Element</div>

<!-- Column_holder.ss -->
<div class="$HolderClasses.ATT" data-element="$SimpleClassName.LowerCase" id="$Anchor">$Element</div>
```

Every holder carries a `data-element` attribute derived from `SimpleClassName.LowerCase` — the lowercased short class name. For the shipped containers that's `section`, `row`, and `column`; a subclass like `App\Grid\Sections\HeroSection` would render `data-element="herosection"`. The attribute is stable for E2E selectors and frontend scripts to target, regardless of `ExtraClass` or theme class overrides.

### Class contribution

`getHolderClasses()` on `GridElement` concatenates:

1. `Style` (DB field, user-picked)
2. `ExtraClass` (DB field, power-user CSS hook)
3. Anything returned by `provideHolderClasses()` (subclass override)
4. Anything appended via the `updateHolderClasses` extension hook

`Row` and `Column` override `provideHolderClasses()` to add the `GridAdapterInterface`-generated width/offset/visibility classes. Content elements don't override it — their styling typically lives inside the inner template.

## Overriding module templates

SilverStripe resolves templates by namespace. The module's templates live under `templates/WeDevelop/Grid/Model/` and `templates/WeDevelop/Grid/Includes/`. A theme that wants to change rendering copies the file into the theme's matching namespace:

```
themes/my-theme/templates/WeDevelop/Grid/Model/Section_holder.ss
themes/my-theme/templates/WeDevelop/Grid/Model/Column.ss
themes/my-theme/templates/WeDevelop/Grid/Includes/MediaBlock.ss
```

Once `my-theme` is registered in the theme cascade, its copies win over the module's defaults.

Don't edit the module's files directly — your overrides will disappear on the next composer update. Always copy into the theme.

## Customising container output

Each container exposes a distinct extension hook for the classes on its grid wrapper, fired just before rendering:

| Element | Method | Hook |
|---------|--------|------|
| `Section` | `getContainerClasses()` | `updateContainerClasses` |
| `Row` | `getRowClasses()` | `updateRowClasses` |
| `Column` | `getColumnClasses()` | `updateColumnClasses` |

In addition, **every** element fires `updateHolderClasses` from `GridElement::getHolderClasses()` — use that hook to touch the outer holder element's classes (see [Class contribution](#class-contribution)) on any element, container or content.

Register an extension on the element class to inject framework-specific classes without forking the template:

```php
// app/_config/grid.yml
WeDevelop\Grid\Model\Section:
  extensions:
    - App\Extensions\SectionThemeExtension
```

```php
namespace App\Extensions;

use SilverStripe\Core\Extension;

class SectionThemeExtension extends Extension
{
    public function updateContainerClasses(string &$classes): void
    {
        $classes .= ' my-theme__section';
    }
}
```

## The `$Me` loop pattern

Using `$Me` in loops lets the element pick its own template chain:

```silverstripe
<% loop $Sections %>$Me<% end_loop %>          {# → Section_holder.ss → Section.ss          #}
<% loop $Rows %>$Me<% end_loop %>              {# → Row_holder.ss → Row.ss                  #}
<% loop $Columns %>$Me<% end_loop %>           {# → Column_holder.ss → Column.ss            #}
<% loop $Elements %>$Me<% end_loop %>          {# → {ClassName}_holder.ss → {ClassName}.ss  #}
```

`Section.ss` loops `$Rows`, `Row.ss` loops `$Columns`, `Column.ss` loops `$Elements`. The loops are `has_many` relations defined on the container models — if you need to filter (e.g. hide unpublished children on live), do it in a getter on the model, not in the template.

## Responsive images (BlockMediaExtension)

When `BlockMediaExtension` is applied to a content element, the template receives a `MediaBlock` include:

```silverstripe
<% include WeDevelop/Grid/Includes/MediaBlock %>
```

The include handles image/video discrimination, aspect-ratio wrapping, captions, and responsive image sizing via the grid adapter's `getContainerMaxWidth()` + `getColumnPixelWidth()` methods. Override the include's template in your theme to customise wrapping markup; `BlockMediaExtension` itself doesn't need to change.

## Frontend asset pipeline

The module ships a compiled bundle at `client/dist/` (exposed via composer's `extra.expose`). CMS pages serve it automatically. Project-level CSS is outside the module's scope — import the shipped CSS variables and ship your own styles.

The grid requires the `SS_GRID_ADAPTER` env var to be set (a preset name — `bootstrap`, `tailwind`, or `bulma` — or a custom adapter FQCN); it selects which CSS framework's width/offset/visibility classes the Row and Column holders emit. An unset or invalid value throws at container boot. See [Grid Adapter System](../architecture/grid-adapter.md).

## See also

- [The grid editor](grid-editor.md) — what CMS users build with the structure these templates render
- [Custom content elements](custom-elements.md) — adding new block types
- [Backend Architecture](../architecture/backend.md#cms-integration) — how `GridPageExtension` wires `$UseGrid` and `$Sections`
- [Grid Adapter System](../architecture/grid-adapter.md) — how width/offset classes get generated for Section/Row/Column holders
