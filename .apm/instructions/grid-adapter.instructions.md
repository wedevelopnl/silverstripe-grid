---
description: Grid adapter architecture summary and pointer to full reference
applyTo: "**/*"
---

# Grid Adapter System

Grid adapters translate the abstract grid model (viewports, column widths, offsets, visibility) into CSS framework-specific class names. All consumers depend on `GridAdapterInterface`, never on a concrete adapter.

The adapter is entirely configuration-driven. `GridAdapter` is a single concrete base class that reads CSS format strings, class maps, and scalar values from SilverStripe `Configurable` statics. Framework presets (BootstrapAdapter, TailwindAdapter, BulmaAdapter) are zero-method subclasses that only declare `private static` property overrides.

**Full reference (implementation walkthrough, config property tables, content layout):** [`docs/architecture/grid-adapter.md`](../../docs/architecture/grid-adapter.md)

## Key Files

- `src/Contract/GridAdapterInterface.php` — 14 methods defining the grid adapter contract
- `src/Contract/ContentLayoutAdapterInterface.php` — 8 methods for content layout CSS
- `src/Adapter/GridAdapter.php` — Config-driven base class implementing both interfaces
- `src/Adapter/BootstrapAdapter.php` / `TailwindAdapter.php` / `BulmaAdapter.php` — presets
- `src/Factory/GridAdapterFactory.php` — Injector factory that aliases additional bindings to the `GridAdapterInterface` singleton
- `_config/grid.yml` — DI binding (default: `BootstrapAdapter`)
- `_config/content-layout.yml` — DI alias for `ContentLayoutAdapterInterface` (via `GridAdapterFactory`)

## Existing Presets

| Preset | Default Columns | Default Viewport | Viewports |
|--------|----------------|------------------|-----------|
| `BootstrapAdapter` | 12 | `md` | xs, sm, md, lg, xl, xxl |
| `TailwindAdapter` | 12 | `sm` | sm, md, lg, xl, 2xl |
| `BulmaAdapter` | 12 | `desktop` | mobile, tablet, desktop, widescreen, fullhd |

## Core Rules

- A new adapter is a zero-method subclass of `GridAdapter` with `private static` property overrides only — no method overrides.
- Any property can be overridden per-project via YAML without writing PHP.
- `base_viewport_key` identifies the "no infix" viewport (Bootstrap's `xs`, Bulma's `mobile`); set to `null` for frameworks without one (Tailwind).
- `ContentLayoutAdapterInterface` is implemented by the same `GridAdapter` instance — both interfaces resolve to one singleton via `GridAdapterFactory` in `_config/content-layout.yml`.
- Override strategy (`isolated` vs `cascade`) lives on `GridSettingsResolver`, **not** on the adapter.

For the full property reference, sprintf arg tables, implementation walkthrough, and content layout details, see `docs/architecture/grid-adapter.md`.
