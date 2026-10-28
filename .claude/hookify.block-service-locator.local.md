---
name: block-service-locator
enabled: true
event: file
action: warn
conditions:
  - field: content
    operator: regex_match
    pattern: Injector::inst\(\)\s*->(get|create)\(
  - field: file_path
    operator: regex_match
    pattern: src/
---

**Service Locator Call — Prefer Injection**

`Injector::inst()->get()` / `->create()` hides a dependency from the class signature and makes it harder to substitute in tests. Inject instead, following the SilverStripe Dependency Injection rules in CLAUDE.md (PHP Conventions).

The locator is accepted only where injection is impossible or deliberately avoided, with a comment saying why:

- extensions, which the framework instantiates without DI args (`HierarchyValidationExtension`, `MediaExtension`);
- a `DBComposite` field (`DBGridSettings`) and value objects (`WriteResult`);
- a dependency resolved lazily on purpose (`SharedBlockLocaliser::cloner()` — its constructor wiring exists only in the Fluent-gated config);
- `Injector::inst()->create($class)` for a class chosen at runtime (`GridElementService`).

If this call is in a class that can take injected dependencies, inject instead.
