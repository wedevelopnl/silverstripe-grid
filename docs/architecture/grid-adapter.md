# Grid Adapter System

Grid adapters translate the abstract grid model (viewports, column widths, offsets, visibility) into CSS framework-specific class names. All consumers depend on `GridAdapterInterface`, never on a concrete adapter.

The adapter is entirely configuration-driven. `GridAdapter` is a single `abstract` base class that reads CSS format strings, class maps, and scalar values from SilverStripe `Configurable` statics. Framework presets (BootstrapAdapter, TailwindAdapter, BulmaAdapter) are zero-method subclasses that only declare `private static` property overrides.

## Key Files

- `src/Contract/GridAdapterInterface.php` — the grid adapter contract
- `src/Contract/ContentLayoutAdapterInterface.php` — the content layout CSS contract
- `src/Adapter/GridAdapter.php` — Config-driven base class implementing both interfaces
- `src/Adapter/BootstrapAdapter.php` — Bootstrap 5 preset (zero methods, only statics)
- `src/Adapter/TailwindAdapter.php` — Tailwind CSS preset (zero methods, only statics)
- `src/Adapter/BulmaAdapter.php` — Bulma preset (zero methods, only statics)
- `src/Factory/GridAdapterFactory.php` — Injector factory that aliases `ContentLayoutAdapterInterface` to the `GridAdapterInterface` singleton
- `src/Factory/GridAdapterResolver.php` — Injector factory that selects the adapter from the `SS_GRID_ADAPTER` env var (preset name or FQCN)
- `src/Value/Viewport.php` — Value object (`final readonly class`, not an enum)
- `src/Value/ContainerType.php` — Enum: `Section`, `Row`, `Column`
- `_config/grid.yml` — DI binding (resolved via `GridAdapterResolver`; `SS_GRID_ADAPTER` is required)
- `_config/content-layout.yml` — DI binding for `ContentLayoutAdapterInterface` (via `GridAdapterFactory`). `BlockMediaExtension` is intentionally NOT applied here — image/video capability is opt-in per project

## Existing Presets

| Preset | Default Columns | Default Viewport | Viewports |
|--------|----------------|------------------|-----------|
| `BootstrapAdapter` | 12 | `md` | xs, sm, md, lg, xl, xxl |
| `TailwindAdapter` | 12 | `sm` | base, sm, md, lg, xl, 2xl |
| `BulmaAdapter` | 12 | `desktop` | mobile, tablet, desktop, widescreen, fullhd |

> **Bulma and media order classes.** Bulma ships no flex-order utilities, so
> `BulmaAdapter`'s `order_class_format` / `responsive_order_format` emit a module
> convention (`has-order-1`, `has-order-2-desktop`, …) that no framework CSS backs.
> A Bulma project using the side-by-side media layout of `BlockMediaExtension` must
> either supply that CSS itself or override both formats with its own utility names.
> Every other class the Bulma preset emits is a real Bulma helper.

## Implementing a New Adapter

### 1. Create a Preset Subclass

A new framework adapter is a zero-method subclass of `GridAdapter` with `private static` property overrides declaring the framework's CSS vocabulary:

```php
namespace WeDevelop\Grid\Adapter;

final class YourAdapter extends GridAdapter
{
    // ─── Grid topology ──────────────────────────────────────────
    /** @var array<non-empty-string, array{label: non-empty-string, min_width: int<0, max>}> */
    private static array $viewport_definitions = [
        'xs' => ['label' => 'Mobile', 'min_width' => 0],
        'sm' => ['label' => 'Small',  'min_width' => 640],
        'md' => ['label' => 'Medium', 'min_width' => 768],
        'lg' => ['label' => 'Large',  'min_width' => 1024],
    ];

    /** @var positive-int */
    private static int $total_columns = 12;

    /** @var positive-int */
    private static int $container_max_width = 1320;

    private static string $default_viewport = 'md';

    // ─── Width & offset formats ─────────────────────────────────
    private static ?string $base_viewport_key = 'xs';
    private static string $base_width_format = 'col-span-%d';
    private static string $responsive_width_format = '%1$s:col-span-%2$d';
    private static string $base_offset_format = 'col-start-%d';
    private static string $responsive_offset_format = '%1$s:col-start-%2$d';
    private static int $offset_adjustment = 0;

    // ─── Visibility formats ─────────────────────────────────────
    private static string $base_hide_class = 'hidden';
    private static string $responsive_hide_format = '%s:hidden';
    private static string $responsive_restore_format = '%s:block';

    // ─── Container & structure ──────────────────────────────────
    private static string $row_class_format = 'grid grid-cols-%d';
    private static string $container_class = 'container';
    private static string $fluid_container_class = 'w-full';
    /** @var array<string, string> */
    private static array $title_class_options = ['text-xl' => 'Heading 1'];
    private static string $offset_strategy = 'margin';

    // ─── Content layout ─────────────────────────────────────────
    /** @var array<string, ?string> */
    private static array $aspect_ratio_classes = [
        'auto' => null,
        '1x1' => 'aspect-square',
        '4x3' => 'aspect-[4/3]',
        '16x9' => 'aspect-video',
    ];
    /** @var array<string, string> */
    private static array $vertical_alignment_classes = [
        'top' => 'items-start',
        'center' => 'items-center',
        'bottom' => 'items-end',
    ];
    private static string $order_class_format = 'order-%d';
    private static string $responsive_order_format = '%1$s:order-%2$d';
    /** @var array<string, string> */
    private static array $padding_direction_map = ['left' => 'pl', 'right' => 'pr'];
    private static string $padding_format = '%2$s:%1$s-%3$d';
    private static ?string $base_column_class = null;
}
```

### 2. Config Property Reference

All properties are `private static` on `GridAdapter`. Preset subclasses override them. Projects can further override any property via YAML.

**Grid topology:**

| Property | Type | Purpose |
|----------|------|---------|
| `viewport_definitions` | `array<non-empty-string, array{label: non-empty-string, min_width: int<0, max>}>` | key → `{label, min_width}`, ordered small→large. `min_width` (px) feeds `Viewport->minWidth`. Malformed entries throw `InvalidGridValueException::forMalformedViewportDefinition` at boot |
| `total_columns` | `positive-int` | Grid column count |
| `container_max_width` | `positive-int` | Max container width in px |
| `default_viewport` | `string` | Default viewport key for CMS editor |
| `enabled_viewports` | `list<string>\|null` | Restrict viewports (null = all) |

**Width & offset formats** (sprintf args: `%1$s` = viewport, `%2$d` = value):

| Property | Type | Purpose |
|----------|------|---------|
| `base_viewport_key` | `?string` | Viewport using base format — must be the smallest (`min_width` 0) |
| `base_width_format` | `string` | Base width class (`%d` = width). May emit several space-separated classes — use `%1$d` when the value repeats |
| `responsive_width_format` | `string` | Responsive width class |
| `base_offset_format` | `string` | Base offset class (`%d` = offset). Same multi-class rule as `base_width_format` |
| `responsive_offset_format` | `string` | Responsive offset class |
| `offset_adjustment` | `int` | Added to offset before formatting (0 or 1) |

**Visibility formats** (sprintf arg: `%s` = viewport):

| Property | Type | Purpose |
|----------|------|---------|
| `base_hide_class` | `string` | Hide class for base viewport |
| `responsive_hide_format` | `string` | Hide class for other viewports |
| `hide_class_overrides` | `array<string, string>` | Literal hide class per viewport key, for viewports the format cannot express. Takes precedence over both properties above. Bulma uses it for `fullhd`, which has no `-only` variant. |
| `responsive_restore_format` | `string` | Restore class |

**Container & structure:**

| Property | Type | Purpose |
|----------|------|---------|
| `row_class_format` | `string` | Row classes (`%d` = column count, unused arg OK) |
| `container_class` | `string` | Container wrapper class |
| `fluid_container_class` | `string` | Fluid container class |
| `title_class_options` | `array<string, string>` | CSS class → label for title dropdown |
| `offset_strategy` | `string` | `'margin'` or `'grid-placement'` |

**Content layout** (format args vary per property — see GridAdapter docblock):

| Property | Type | Purpose |
|----------|------|---------|
| `aspect_ratio_classes` | `array<string, ?string>` | AspectRatio value → CSS class. **Must declare every `AspectRatio` enum case** — `GridAdapter::getAspectRatioClass()` throws `InvalidGridValueException` on a missing key (fail-fast rather than render broken markup). Use `null` for the "no constraint" value (e.g. `'auto' => null`). |
| `vertical_alignment_classes` | `array<string, string>` | VerticalAlignment value → CSS class |
| `order_class_format` | `string` | Fixed order (`%d` = position 1 or 2) |
| `responsive_order_format` | `string` | Responsive order (`%1$s` = viewport, `%2$d` = position) |
| `padding_direction_map` | `array<string, string>` | `'left'\|'right'` → CSS prefix |
| `padding_format` | `string` | Padding (`%1$s` = prefix, `%2$s` = viewport, `%3$d` = size) |
| `base_column_class` | `?string` | Framework base class (e.g. Bulma's `'column'`), null if not needed. Not content-layout-only: `ColumnClassResolver` emits it on every grid column too, ahead of the width classes |

### 3. Base Viewport Pattern

Every adapter needs a "base" viewport: the smallest one, with `min_width` 0, named by `base_viewport_key`. At that viewport the base format strings are used (no viewport infix); all other viewports use the responsive format strings.

**This is not optional.** The row wrapper's `row_class_format` has no responsive variant, so the grid is declared from 0px up. `ColumnClassResolver` always emits a width class at the smallest viewport — if that class carries a breakpoint prefix, it matches nothing below the breakpoint and every column falls back to `grid-column: auto`, one track out of `total_columns`. Leaving `base_viewport_key` at `null`, or filtering the named key out via `enabled_viewports`, produces columns crushed to ~8% width on phones.

Frameworks that name their zero-width tier expose it directly (Bootstrap's `xs`, Bulma's `mobile`). Tailwind does not name it — an unprefixed utility *is* the 0px tier — so `TailwindAdapter` models it as a synthetic `base` viewport sitting below `sm` (640px).

**Naming the tier is not enough — the emitted class has to apply there.** What matters is the media query the class ends up in, not whether it carries an infix. Bootstrap's `col-6` and Tailwind's `col-span-6` are unscoped and cascade upward, so one class covers 0px→∞. Bulma splits the same range in two: `is-6` lives in `@media (min-width: 769px)` and `is-6-mobile` in `@media (max-width: 768px)`. `BulmaAdapter` therefore sets `base_width_format` to `'is-%1$d-mobile is-%1$d'` — a base format may emit several space-separated classes, and Bulma's must, or the phone band gets no width rule and every column renders full width.

Bulma also rules out raising the base arm's specificity to compensate. Its `is-{n}-{vp}` classes share a media block and a specificity with the unsuffixed `is-{n}`, so adding an `is-mobile` modifier to `row_class_format` — which brings in the unscoped `.columns.is-mobile > .column.is-{n}` rule at one class higher — makes the base width outrank every override above it. The consequence is that Bulma columns are *sized* from 0px up but still stack below 769px, since `.columns` only becomes a flex container there.

`getBaseWidthClass()` / `getBaseOffsetClass()` use the same base format strings for the CMS editor preview, independent of which viewport is the base one.

### 4. Register the Adapter

`GridAdapterInterface` resolves through `GridAdapterResolver`, which reads the required `SS_GRID_ADAPTER` environment variable. The value is either a bundled preset name (`bootstrap`|`tailwind`|`bulma`, case-insensitive) or the FQCN of an adapter that implements **both** `GridAdapterInterface` and `ContentLayoutAdapterInterface`. Both interfaces are aliased to the same singleton (see `_config/content-layout.yml`), so an adapter implementing only the former would boot cleanly and fail later at render — the resolver rejects it up front instead. Subclassing `GridAdapter` satisfies both. Unset, empty, or invalid values throw at container boot.

For a bundled preset:

```yaml
environment:
  SS_GRID_ADAPTER: tailwind
```

For a custom adapter, pass the FQCN through the same variable:

```yaml
environment:
  SS_GRID_ADAPTER: Vendor\App\Adapter\YourAdapter
```

`.docker/env.sh` seeds `SS_GRID_ADAPTER=tailwind` into the generated `.docker/.env`, so first-run `task up` succeeds; edit the file or set the variable in your shell to switch.

### 5. Optional: YAML Configuration

Any property can be overridden per-project without PHP:

```yaml
WeDevelop\Grid\Adapter\YourAdapter:
  enabled_viewports:
    - sm
    - md
    - lg
  total_columns: 16
  default_viewport: md
```

### 6. Override Strategy (Module Config)

The override strategy (isolated vs cascade) is a module-level setting on `GridSettingsResolver`, not the adapter:

```yaml
WeDevelop\Grid\Service\GridSettingsResolver:
  override_strategy: cascade
```

## Content Layout

Content layout (aspect ratios, media ordering, vertical alignment, directional padding) is handled by `ContentLayoutAdapterInterface`, implemented directly by `GridAdapter`. The same adapter instance serves both `GridAdapterInterface` and `ContentLayoutAdapterInterface`.

### ContentLayoutAdapterInterface

| Method | Returns | Purpose |
|--------|---------|---------|
| `getAspectRatioClass(AspectRatio)` | `?string` | Aspect ratio constraint (null for Auto) |
| `getVerticalAlignmentClass(VerticalAlignment)` | `string` | Flex/grid row alignment |
| `getMediaOrderClasses(MediaPosition)` | `string` | CSS order for media column |
| `getContentOrderClasses(MediaPosition)` | `string` | CSS order for content column |
| `getMediaWidthClass(int)` | `string` | Width class for media column |
| `getContentWidthClass(int)` | `string` | Width class for content column |
| `getPaddingClass(direction, size)` | `string` | Directional padding/margin for gap |
| `getBaseColumnClass()` | `?string` | Framework base class (e.g. Bulma's `column`). Also declared on `GridAdapterInterface`, which needs it for the grid's own columns — one implementation serves both |

### How the content layout adapter is resolved

`ContentLayoutAdapterInterface` is aliased to the `GridAdapterInterface` singleton via `GridAdapterFactory` in `_config/content-layout.yml`:

```yaml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\ContentLayoutAdapterInterface:
    factory: WeDevelop\Grid\Factory\GridAdapterFactory
```

`GridAdapterFactory` resolves `GridAdapterInterface` from the Injector and returns it, so both interfaces share the same adapter singleton. Consumers can inject `ContentLayoutAdapterInterface` directly via DI rather than casting from the grid adapter.

### Value Objects

| Class | Purpose |
|-------|---------|
| `AspectRatio` | Enum: Auto, Square (1x1), FourByThree (4x3), SixteenByNine (16x9) |
| `MediaPosition` | Enum: First, Last, LastOnDesktop (mobile-first default, responsive on desktop) |
| `VerticalAlignment` | Enum: Top, Center, Bottom |
