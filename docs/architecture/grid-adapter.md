# Grid Adapter System

Grid adapters translate the abstract grid model (viewports, column widths, offsets, visibility) into CSS framework-specific class names. All consumers depend on `GridAdapterInterface`, never on a concrete adapter.

The adapter is entirely configuration-driven. `GridAdapter` is a single concrete base class that reads CSS format strings, class maps, and scalar values from SilverStripe `Configurable` statics. Framework presets (BootstrapAdapter, TailwindAdapter, BulmaAdapter) are zero-method subclasses that only declare `private static` property overrides.

## Key Files

- `src/Contract/GridAdapterInterface.php` — 14 methods defining the grid adapter contract
- `src/Contract/ContentLayoutAdapterInterface.php` — 8 methods for content layout CSS
- `src/Adapter/GridAdapter.php` — Config-driven base class implementing both interfaces
- `src/Adapter/BootstrapAdapter.php` — Bootstrap 5 preset (zero methods, only statics)
- `src/Adapter/TailwindAdapter.php` — Tailwind CSS preset (zero methods, only statics)
- `src/Adapter/BulmaAdapter.php` — Bulma preset (zero methods, only statics)
- `src/Factory/GridAdapterFactory.php` — Injector factory that aliases `ContentLayoutAdapterInterface` to the `GridAdapterInterface` singleton
- `src/Factory/GridAdapterResolver.php` — Injector factory that selects the adapter preset from the `SS_GRID_ADAPTER` env var
- `src/Value/Viewport.php` — Value object (`final readonly class`, not an enum)
- `src/Value/ContainerType.php` — Enum: `Section`, `Row`, `Column`
- `_config/grid.yml` — DI binding (resolved via `GridAdapterResolver`; default preset: `bootstrap`)
- `_config/content-layout.yml` — DI alias for `ContentLayoutAdapterInterface` (via `GridAdapterFactory`) + applies `BlockMediaExtension` to `ContentElement`

## Existing Presets

| Preset | Default Columns | Default Viewport | Viewports |
|--------|----------------|------------------|-----------|
| `BootstrapAdapter` | 12 | `md` | xs, sm, md, lg, xl, xxl |
| `TailwindAdapter` | 12 | `sm` | sm, md, lg, xl, 2xl |
| `BulmaAdapter` | 12 | `desktop` | mobile, tablet, desktop, widescreen, fullhd |

## Implementing a New Adapter

### 1. Create a Preset Subclass

A new framework adapter is a zero-method subclass of `GridAdapter` with `private static` property overrides declaring the framework's CSS vocabulary:

```php
namespace WeDevelop\Grid\Adapter;

final class YourAdapter extends GridAdapter
{
    // ─── Grid topology ──────────────────────────────────────────
    /** @var array<string, string> */
    private static array $viewport_definitions = [
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
    ];

    /** @var positive-int */
    private static int $total_columns = 12;

    /** @var positive-int */
    private static int $container_max_width = 1320;

    private static string $default_viewport = 'md';

    // ─── Width & offset formats ─────────────────────────────────
    private static ?string $base_viewport_key = null;
    private static string $base_width_format = 'col-span-%d';
    private static string $responsive_width_format = '%1$s:col-span-%2$d';
    private static string $base_offset_format = 'col-start-%d';
    private static string $responsive_offset_format = '%1$s:col-start-%2$d';
    private static int $offset_adjustment = 0;

    // ─── Visibility formats ─────────────────────────────────────
    private static string $base_hide_class = '';
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
| `viewport_definitions` | `array<string, string>` | key → label, ordered small→large |
| `total_columns` | `positive-int` | Grid column count |
| `container_max_width` | `positive-int` | Max container width in px |
| `default_viewport` | `string` | Default viewport key for CMS editor |
| `enabled_viewports` | `list<string>\|null` | Restrict viewports (null = all) |

**Width & offset formats** (sprintf args: `%1$s` = viewport, `%2$d` = value):

| Property | Type | Purpose |
|----------|------|---------|
| `base_viewport_key` | `?string` | Viewport using base format; null if none |
| `base_width_format` | `string` | Base width class (`%d` = width) |
| `responsive_width_format` | `string` | Responsive width class |
| `base_offset_format` | `string` | Base offset class (`%d` = offset) |
| `responsive_offset_format` | `string` | Responsive offset class |
| `offset_adjustment` | `int` | Added to offset before formatting (0 or 1) |

**Visibility formats** (sprintf arg: `%s` = viewport):

| Property | Type | Purpose |
|----------|------|---------|
| `base_hide_class` | `string` | Hide class for base viewport |
| `responsive_hide_format` | `string` | Hide class for other viewports |
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
| `base_column_class` | `?string` | Framework base class (e.g. Bulma's `'column'`), null if not needed |

### 3. Base Viewport Pattern

Frameworks with a "base" viewport (Bootstrap's `xs`, Bulma's `mobile`) set `base_viewport_key` to that viewport's key. At that viewport, the base format strings are used (no viewport infix). All other viewports use the responsive format strings.

Frameworks without a base viewport (Tailwind) set `base_viewport_key` to `null` — all viewports use the responsive format. The base format strings are still used for `getBaseWidthClass()` / `getBaseOffsetClass()` (CMS editor preview).

### 4. Register the Adapter

`GridAdapterInterface` resolves through `GridAdapterResolver`, which picks the preset from the `SS_GRID_ADAPTER` environment variable (`bootstrap`|`tailwind`|`bulma`, case-insensitive). Unset defaults to `bootstrap`; unknown values throw at boot.

For built-in presets, set the env var (for example in `.docker/compose.yml` or the CI job env):

```yaml
environment:
  SS_GRID_ADAPTER: tailwind
```

For a custom adapter, rebind `GridAdapterInterface` directly in project-level YAML. This bypasses the resolver:

```yaml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\GridAdapterInterface:
    class: WeDevelop\Grid\Adapter\YourAdapter
```

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

### ContentLayoutAdapterInterface (8 methods)

| Method | Returns | Purpose |
|--------|---------|---------|
| `getAspectRatioClass(AspectRatio)` | `?string` | Aspect ratio constraint (null for Auto) |
| `getVerticalAlignmentClass(VerticalAlignment)` | `string` | Flex/grid row alignment |
| `getMediaOrderClasses(MediaPosition)` | `string` | CSS order for media column |
| `getContentOrderClasses(MediaPosition)` | `string` | CSS order for content column |
| `getMediaWidthClass(int)` | `string` | Width class for media column |
| `getContentWidthClass(int)` | `string` | Width class for content column |
| `getPaddingClass(direction, size)` | `string` | Directional padding/margin for gap |
| `getBaseColumnClass()` | `?string` | Framework base class (e.g. Bulma's `column`) |

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
