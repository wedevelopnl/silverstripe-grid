---
description: Grid adapter architecture, interface contract, and how to implement new CSS framework adapters
applyTo: "**/*"
---

# Grid Adapter System

## Architecture

Grid adapters translate the abstract grid model (viewports, column widths, offsets, visibility) into CSS framework-specific class names. All consumers depend on `GridAdapterInterface`, never on a concrete adapter.

### Key Files

- `src/Contract/GridAdapterInterface.php` — 15 methods defining the adapter contract
- `src/Adapter/AbstractGridAdapter.php` — Abstract base class with shared config, viewport management, and visibility map
- `src/Value/Viewport.php` — Value object (`final readonly class`, not an enum)
- `src/Value/ContainerType.php` — Enum: `Section`, `Row`, `Column`
- `_config/grid.yml` — DI binding (default: `BootstrapAdapter`)
- `src/Contract/ContentLayoutAdapterInterface.php` — 8 methods for content layout CSS
- `src/Adapter/ContentLayoutAdapter.php` — Unified, data-driven content layout adapter
- `src/Value/ContentLayoutClassMap.php` — Per-framework CSS class mappings
- `_config/content-layout.yml` — DI binding for content layout adapter + BlockMediaExtension

### Existing Adapters

| Adapter | Default Columns | Default Viewport | Viewports |
|---------|----------------|------------------|-----------|
| `BootstrapAdapter` | 12 | `md` | xs, sm, md, lg, xl, xxl |
| `TailwindAdapter` | 12 | `sm` | sm, md, lg, xl, 2xl |
| `BulmaAdapter` | 12 | `desktop` | mobile, tablet, desktop, widescreen, fullhd |

## Implementing a New Adapter

### 1. Create the Adapter Class

```php
namespace WeDevelop\Grid\Adapter;

use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

final class YourAdapter extends AbstractGridAdapter
{
    public function __construct()
    {
        parent::__construct(
            allViewports: [
                'sm' => new Viewport('sm', 'Small'),
                'md' => new Viewport('md', 'Medium'),
                'lg' => new Viewport('lg', 'Large'),
            ],
            defaultColumns: 12,
            defaultContainerMaxWidth: 1320,
            defaultViewportKey: 'md',
        );
    }

    // Implement abstract methods...
}
```

### 2. `AbstractGridAdapter` Base Class

The base class provides YAML-configurable properties (set on the concrete adapter class in project YAML):

| Property | Type | Default | Purpose |
|----------|------|---------|---------|
| `$enabled_viewports` | `list<string>\|null` | `null` (all active) | Restrict which viewports are available |
| `$total_columns` | `int\|null` | `null` (adapter default) | Override total column count |
| `$default_viewport` | `string\|null` | `null` (adapter default) | Override default viewport key |
| `$container_max_width` | `int\|null` | `null` (adapter default) | Override container max width |
| `$override_strategy` | `string\|null` | `null` (isolated) | Override strategy: `isolated` or `cascade` |

The base class handles in its constructor:
- `applyViewportFilter()` — Filters the full viewport map to only enabled viewports. Throws `InvalidGridValueException` if empty array or unknown key.
- `resolveColumnCount()` — Returns YAML override if set, else adapter default. Throws if override is `<= 0`.
- `resolveDefaultViewport()` — Resolves the effective default viewport. Throws if key not found in active viewports.
- `resolveContainerMaxWidth()` — Returns YAML override if set, else adapter default. Throws if override is `<= 0`.
- `buildVisibilityMap()` — Pre-computes visibility class pairs using `formatHideClass()` and `formatRestoreClass()`.

Final getters provided by the base class (no need to implement):
- `getViewports()`, `getColumnCount()`, `getDefaultViewport()`, `getContainerMaxWidth()`, `getVisibilityClasses()`, `getOverrideStrategy()`

### 3. Methods to Implement

| Method | Returns | Notes |
|--------|---------|-------|
| `getWidthClass($viewport, $width)` | `string` | Framework-specific width class |
| `getOffsetClass($viewport, $offset)` | `string` | Framework-specific offset class |
| `getBaseWidthClass($width)` | `string` | Width class for base/default viewport |
| `getBaseOffsetClass($offset)` | `string` | Offset class for base/default viewport |
| `getRowClasses()` | `string` | Row container classes |
| `getContainerClass($fluid)` | `string` | Container wrapper classes |
| `getTitleClassOptions()` | `array<string, string>` | CSS class → human label mapping |
| `getOffsetStrategy()` | `OffsetStrategy` | Margin-based vs grid-placement offset |
| `getContentLayoutClassMap()` | `ContentLayoutClassMap` | CSS class mappings for content layout adapter |
| `formatHideClass($viewportKey)` | `string` | CSS class to hide at this viewport |
| `formatRestoreClass($viewportKey)` | `string` | CSS class to restore visibility at this viewport |

### 4. Visibility Classes Pattern

The base class generates hide+restore pairs using the abstract format hooks. For a viewport that is NOT the last active viewport:
- First class: `formatHideClass(key)` — hides from this viewport upward
- Second class: `formatRestoreClass(nextKey)` — restores at the next active viewport

For the last active viewport, only the hide class is needed.

Some frameworks have a "base" viewport with no prefix (Bootstrap's `xs`, Bulma's `mobile`) — handle these as special cases in `formatHideClass()`.

### 5. Register the Adapter

In `_config/grid.yml` (or project-level YAML):

```yaml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\GridAdapterInterface:
    class: WeDevelop\Grid\Adapter\YourAdapter
```

### 6. Optional: YAML Configuration

```yaml
WeDevelop\Grid\Adapter\YourAdapter:
  enabled_viewports:
    - sm
    - md
    - lg
  total_columns: 16
  default_viewport: md
  override_strategy: cascade
```

### 7. Provide Content Layout Class Map

Implement `getContentLayoutClassMap()` by adding a static factory to `ContentLayoutClassMap`:

```php
public function getContentLayoutClassMap(): ContentLayoutClassMap
{
    return ContentLayoutClassMap::yourFramework();
}
```

The `ContentLayoutClassMap` factory defines framework-specific CSS strings for aspect ratios, vertical alignment, ordering, padding direction, and base column classes. See `ContentLayoutClassMap::bootstrap()` for reference.

## Content Layout Adapter System

Complements the grid adapter for content-level layout concerns: aspect ratios, media ordering, vertical alignment, and directional padding. Unlike grid adapters (one class per framework), the content layout system uses a single `ContentLayoutAdapter` driven by per-framework data in `ContentLayoutClassMap`.

### Architecture

```
GridAdapterInterface::getContentLayoutClassMap()
  └── ContentLayoutClassMap (per-framework CSS strings)
        └── ContentLayoutAdapter (unified implementation)
              └── ContentLayoutAdapterInterface (8 methods)
```

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

### ContentLayoutClassMap

`final readonly class` with static factories per framework. Each factory returns all CSS strings needed for content layout:

- `aspectRatioClasses` — enum value → CSS class (null for Auto)
- `verticalAlignmentClasses` — enum value → CSS class
- `orderClass1` / `orderClass2` — fixed order classes
- `responsiveOrderFormat` — sprintf format for responsive order (`%1$s`=viewport, `%2$d`=order)
- `paddingDirectionMap` — `'left'|'right'` → CSS prefix
- `paddingFormat` — sprintf format for padding (`%1$s`=prefix, `%2$s`=viewport, `%3$d`=size)
- `baseColumnClass` — base column class or null

### Value Objects

| Class | Purpose |
|-------|---------|
| `AspectRatio` | Enum: Auto, Square (1x1), FourByThree (4x3), SixteenByNine (16x9) |
| `MediaPosition` | Enum: First, Last, LastOnDesktop (mobile-first default, responsive on desktop) |
| `VerticalAlignment` | Enum: Top, Center, Bottom |

### DI Configuration

```yaml
# _config/content-layout.yml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\ContentLayoutAdapterInterface:
    class: WeDevelop\Grid\Adapter\ContentLayoutAdapter
    constructor:
      gridAdapter: '%$WeDevelop\Grid\Contract\GridAdapterInterface'
```

The adapter receives the active grid adapter via constructor injection and calls `getContentLayoutClassMap()` to obtain framework-specific CSS strings.
