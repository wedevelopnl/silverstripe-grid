---
name: warn-constructor-injection
enabled: true
event: file
action: warn
conditions:
  - field: new_text
    operator: regex_match
    pattern: public function __construct\(
  - field: file_path
    operator: regex_match
    pattern: src/(Model|Extensions|Controllers)/.*\.php$
---

**Constructor Injection Warning — SilverStripe Limitation**

Classes extending `AdminController`, `LeftAndMain`, `DataObject`, or `WeDevelop\Grid\Model\GridElement` **cannot use constructor injection**. SilverStripe instantiates these classes without DI arguments — constructor parameters will silently be `null`.

**Use property injection instead** (see the PHP Conventions in CLAUDE.md; `GridController` and `GridElement` show the pattern):

```php
/** @var array<string, string> */
private static array $dependencies = [
    'myService' => '%$' . MyServiceInterface::class,
];

public MyServiceInterface $myService;
```

If this is a plain service class (not a Controller/DataObject/element), constructor injection is fine — but wire it explicitly in YAML with `constructor:` config.
