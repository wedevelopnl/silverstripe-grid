---
description: PHP conventions including SilverStripe DI patterns and PHPStan quirks
applyTo: "**/*.php"
---

# PHP Conventions

## SilverStripe Dependency Injection

- **Property injection via `$dependencies`**: Controllers (`AdminController` subclasses) and Elements (`DataObject` subclasses) cannot use constructor injection — the framework instantiates them without DI args. Use `private static array $dependencies` for property injection instead. See `GridController` and `Section`/`Row`/`Column` for examples.
- **Injector constructor wiring**: Injector does NOT auto-wire constructor params from YAML interface bindings. Services with constructor injection need explicit `constructor:` config in YAML (see `_config/hierarchy.yml`).

## Result Pattern

- Service-layer validation returns `Result` objects via `Result::ok($value)` / `Result::fail($errors)` — never throws for expected validation failures.
- Used in `ElementPlacementService`, `GridElementService`, `GridSettingsService`, `WriteResult`, and controller response flows.
- Check with `$result->isOk()` / `$result->isErr()`, access value via `$result->unwrap()`, errors via `$result->errors()`.

## Container Auto-Scaffolding

- `GridElement::onAfterWrite()` is the single scaffolding site. The child class to create is derived via `ContainerType::allowedChildClass()`:
  - `ContainerType::Section` → creates a `Row`
  - `ContainerType::Row` → creates a `Column`
  - `ContainerType::Column` → `allowedChildClass()` returns `null`; no scaffolding
- Runs only on DRAFT stage and only when the container has no children.
- Ensures the Section → Row → Column hierarchy is always complete. Integration tests creating elements must account for these auto-created children.
- Per-class `auto_scaffold: false` static disables scaffolding on a subclass.

## PHPStan

- **`positive-int` narrowing**: `!== 0` does not narrow `int` to `positive-int`; use `> 0` (or `<= 0` for the guard clause) instead.

### Type Precision

Use the narrowest PHPStan PHPDoc type that matches the domain constraint. Prefer precise types over wide ones to eliminate unnecessary runtime checks.

| Domain concept | Use | Not |
|----------------|-----|-----|
| Database record IDs (after write) | `positive-int` | `int` |
| Column widths / grid spans | `positive-int` | `int` |
| Column offsets | `int<0, max>` | `int` |
| Viewport keys | `non-empty-string` | `string` |
| Zone names | `non-empty-string` | `string` |
| Error/validation messages | `non-empty-string` | `string` |
| Element class names | `class-string` or `class-string<T>` | `string` |
| Padding/margin sizes (when > 0) | `positive-int` | `int` |
| UI labels displayed to users | `non-empty-string` | `string` |

- All changes are PHPDoc-only (`@param`, `@return`, `@var`) — PHP does not support these types natively.
- When narrowing after a validation guard (e.g., `$id < 1` returns early), add an inline `/** @var positive-int $id */` cast after the guard so PHPStan can track the narrowed type downstream.
- Prefer tightening the source type (e.g., an array shape `@var`) over adding inline casts on each usage — let PHPStan propagate the narrowed type naturally.
- Interface `@param` types propagate to all implementations — update the interface only, do not repeat `@param` PHPDoc on implementing methods.
- For values from `json_decode` or other dynamic sources, use `@var` casts at the decode boundary.
- Do NOT narrow types when the wider type is a real code path (e.g., `$zone` that genuinely can be `''`).
