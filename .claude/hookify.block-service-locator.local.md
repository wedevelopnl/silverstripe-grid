---
name: block-service-locator
enabled: true
event: file
action: warn
conditions:
  - field: new_text
    operator: regex_match
    pattern: Injector::inst\(\)->(get|create)\(
  - field: file_path
    operator: regex_match
    pattern: src/
---

**Service Locator Call — Prefer Injection**

`Injector::inst()->get()` / `->create()` hides a dependency from the class signature and makes it harder to substitute in tests. Prefer the project's DI conventions (see the PHP Conventions in CLAUDE.md):

- **Controllers/DataObjects**: `private static array $dependencies` property injection (SilverStripe instantiates these without DI args) — see `GridController` and `GridElement`.
- **Services**: constructor injection with explicit YAML `constructor:` config — see `ElementPlacementService` wired in `_config/hierarchy.yml`.

The locator is accepted where no DI hook exists, with a comment saying why: extensions (`HierarchyValidationExtension`), a `DBComposite` field (`DBGridSettings`), value objects (`WriteResult`), and `Injector::inst()->create($class)` to instantiate a class chosen at runtime (`GridElementService`). If this call is in a class that can take injected dependencies, inject instead.
