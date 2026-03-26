---
name: grid-adapter-scaffold
description: Scaffolds a new GridAdapter preset subclass for a CSS framework, with all required static config properties and YAML registration. Use when creating a new CSS framework adapter for the grid system.
---

This skill generates a complete grid adapter preset for a new CSS framework. Presets are zero-method subclasses of `GridAdapter` that declare their CSS vocabulary as `private static` Configurable properties.

## Step 1: Gather Requirements

Ask the user for:
1. **CSS framework name** (e.g., "Foundation", "Skeleton", "PureCSS")
2. **Viewport breakpoints**: List of viewport keys and labels, ordered small → large
3. **Default viewport**: Which viewport is the default for the CMS editor
4. **Column count**: Total grid columns (typically 12)
5. **Container max width**: Max container width in px at the largest breakpoint
6. **Base viewport**: Does the framework have a viewport with no prefix in class names? (like Bootstrap's `xs` or Bulma's `mobile`). Set to `null` if all viewports use the same responsive format.
7. **Class name patterns** — ask for examples of:
   - Width class at the base viewport and at a responsive viewport
   - Offset class (and whether it uses 0-based or 1-based positioning)
   - Visibility hide/restore classes
   - Row container class (static string, or dynamic based on column count?)
   - Container class (fixed and fluid variants)
8. **Title class options**: What heading/display classes does the framework offer?
9. **Content layout classes**: Aspect ratio, vertical alignment, ordering, padding patterns
10. **Offset strategy**: Margin-based (like Bootstrap/Bulma) or grid-placement (like Tailwind)?

## Step 2: Generate the Preset Class

Create `src/Adapter/{Name}Adapter.php` — a zero-method subclass of `GridAdapter` with only `private static` property overrides.

Use the existing presets as reference:
- `src/Adapter/BootstrapAdapter.php` — Bootstrap 5 (base viewport: `xs`)
- `src/Adapter/TailwindAdapter.php` — Tailwind CSS (no base viewport, offset adjustment: 1)
- `src/Adapter/BulmaAdapter.php` — Bulma (base viewport: `mobile`, base column class: `column`)

### Format String Conventions

Width/offset formats use sprintf positional args:
- `%1$s` = viewport key, `%2$d` = width/offset value (after adjustment)
- Base formats: `%d` = value only (no viewport)

Order formats: `%1$s` = viewport, `%2$d` = position (1 or 2)
Padding format: `%1$s` = direction prefix, `%2$s` = viewport, `%3$d` = size

Row class format: `%d` = column count (unused arg ignored for static strings like `'row'`)

### Base Viewport Pattern

If `base_viewport_key` is set (e.g., `'xs'`), that viewport uses the base format strings (no viewport infix). All other viewports use the responsive format strings.

If `base_viewport_key` is `null`, all viewports use the responsive format. The base format strings are still used for `getBaseWidthClass()` / `getBaseOffsetClass()` (CMS editor preview).

## Step 3: Register in YAML

Show the user the config to switch to the new adapter:

```yaml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\GridAdapterInterface:
    class: WeDevelop\Grid\Adapter\{Name}Adapter
```

## Step 4: Verify

After generating the preset:
1. Run `make analyse` to verify PHPStan compliance (level max, 100% type coverage)
2. Run `make test-integration` to verify the adapter works with the real config system
3. Verify visibility classes generate correct hide/restore pairs for all viewport combinations

## Step 5: Write Integration Tests

Create `tests/Integration/Adapter/{Name}AdapterTest.php` extending `SapphireTest` with `$usesDatabase = false`. Test ALL method outputs:
- Viewport definitions (count, order, keys, labels)
- Width/offset/base classes across viewports
- Visibility class pairs (full set + filtered viewports)
- Content layout: aspect ratios, alignment, ordering, padding, base column class

Use the existing adapter tests as reference (BootstrapAdapterTest, TailwindAdapterTest, BulmaAdapterTest).

## Reference

- `src/Adapter/GridAdapter.php` — Config-driven base class (see docblock for all 25+ config properties)
- `src/Contract/GridAdapterInterface.php` — 13-method grid contract
- `src/Contract/ContentLayoutAdapterInterface.php` — 8-method content layout contract
