---
name: grid-adapter-scaffold
description: Scaffolds a new GridAdapterInterface implementation extending AbstractGridAdapter, with all required method stubs, viewport definitions, visibility format hooks, and YAML config registration. Use when creating a new CSS framework adapter for the grid system.
---

This skill generates a complete grid adapter implementation for a new CSS framework. It follows the exact patterns established by the existing Bootstrap, Tailwind, and Bulma adapters.

## Step 1: Gather Requirements

Ask the user for:
1. **CSS framework name** (e.g., "Foundation", "Skeleton", "PureCSS")
2. **Viewport breakpoints**: List of viewport keys and labels (e.g., `sm → Small`, `md → Medium`, `lg → Large`)
3. **Default viewport**: Which viewport is the default (most commonly used)
4. **Column count**: Total grid columns (typically 12)
5. **Container max width**: Max container width in px at the largest breakpoint
6. **Base viewport behavior**: Does the framework have a "mobile-first" base viewport with no prefix in class names? (like Bootstrap's `xs` or Bulma's `mobile`)
7. **Class name patterns**: Ask for examples of:
   - Width class (e.g., Bootstrap: `col-md-6`, Tailwind: `md:col-span-6`)
   - Offset class (e.g., Bootstrap: `offset-md-3`, Tailwind: `md:col-start-4`)
   - Visibility hide class (e.g., Bootstrap: `d-md-none`, Tailwind: `md:hidden`)
   - Visibility restore class (e.g., Bootstrap: `d-md-block`, Tailwind: `md:block`)
   - Row container class
   - Container class (fixed and fluid variants)
8. **Title class options**: What heading/display classes does the framework offer?

## Step 2: Generate the Adapter Class

Create `src/Adapter/{Name}Adapter.php` with this structure:

```php
<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

final class {Name}Adapter extends AbstractGridAdapter
{
    public function __construct()
    {
        parent::__construct(
            allViewports: [
                // ... Viewport instances
            ],
            defaultColumns: {columnCount},
            defaultContainerMaxWidth: {maxWidth},
            defaultViewportKey: '{defaultKey}',
        );
    }

    // ... framework-specific interface methods + format hooks
}
```

### Required Methods

Implement the abstract methods from `GridAdapterInterface` (via `AbstractGridAdapter`):
- `getWidthClass(string $viewport, int $width)` → framework-specific width class
- `getOffsetClass(string $viewport, int $offset)` → framework-specific offset class
- `getBaseWidthClass(int $width)` → width class for the base/default viewport
- `getBaseOffsetClass(int $offset)` → offset class for the base/default viewport
- `getRowClasses()` → row container class string
- `getContainerClass(bool $fluid)` → container class (fixed vs fluid)
- `getTitleClassOptions()` → heading class → label mapping
- `getOffsetStrategy()` → `OffsetStrategy::Margin` or `OffsetStrategy::GridPlacement`
- `getContentLayoutClassMap()` → framework-specific content layout class map

And the visibility format hooks:
- `formatHideClass(string $viewportKey)` → CSS class to hide at this viewport
- `formatRestoreClass(string $viewportKey)` → CSS class to restore visibility at this viewport

**Note:** `getViewports()`, `getColumnCount()`, `getDefaultViewport()`, `getContainerMaxWidth()`, and `getVisibilityClasses()` are final methods on the base class — do not implement them.

### Visibility Format Hooks

The base class pre-computes the visibility map using `formatHideClass()` and `formatRestoreClass()`:
- For non-last viewports: `[formatHideClass(key), formatRestoreClass(nextKey)]`
- For the last viewport: `[formatHideClass(key)]`

Handle base viewports (no prefix) as special cases in `formatHideClass()`.

## Step 3: Register in YAML

Update `_config/grid.yml` or create a separate YAML file. Show the user the config to switch to the new adapter:

```yaml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\GridAdapterInterface:
    class: WeDevelop\Grid\Adapter\{Name}Adapter
```

## Step 4: Verify

After generating the adapter:
1. Run `make analyse` to verify PHPStan compliance (level max, 100% type coverage)
2. Check that all method return types satisfy the interface
3. Verify the visibility map generates correct hide/restore pairs for all viewport combinations

## Reference

Study the existing adapters for patterns:
- `src/Adapter/BootstrapAdapter.php` — Bootstrap 5 (base viewport: `xs`, no infix)
- `src/Adapter/TailwindAdapter.php` — Tailwind v3/v4 (all prefixed, offset uses `col-start-{n+1}`)
- `src/Adapter/BulmaAdapter.php` — Bulma (base viewport: `mobile`, uses suffix instead of prefix)
- `src/Adapter/AbstractGridAdapter.php` — Abstract base class with shared config + visibility map
