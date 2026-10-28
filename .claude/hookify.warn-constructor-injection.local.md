---
name: warn-constructor-injection
enabled: true
event: file
action: warn
conditions:
  - field: content
    operator: regex_match
    pattern: public function __construct\(
  - field: file_path
    operator: regex_match
    pattern: src/((Model|Extensions|Controllers|Migration/Model)/.*|UserForms/(UserFormElement|.*Extension))\.php$
---

**Constructor Injection Warning — SilverStripe Limitation**

Controllers, `DataObject`s (every grid element included) and extensions are instantiated by the framework without DI arguments, so a constructor parameter is never injected — the ORM even passes its record array as the first argument. Follow the SilverStripe Dependency Injection rules in CLAUDE.md (PHP Conventions):

- **Controllers and `DataObject`s**: `private static array $dependencies` property injection — see `GridController` and `GridElement`.
- **Extensions**: `$dependencies` does not apply either; resolve the service in the method with `Injector::inst()->get()` and a comment saying why (`HierarchyValidationExtension`, `MediaExtension`).

A plain service class may use constructor injection, wired explicitly in YAML with `constructor:` config.
