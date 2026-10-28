---
name: warn-phpstan-ignore
enabled: true
event: file
action: warn
conditions:
  - field: content
    operator: regex_match
    pattern: @phpstan-ignore
  - field: file_path
    operator: regex_match
    pattern: \.php$
---

**PHPStan Suppression Requires Justification**

This project runs PHPStan at level max with 100% type coverage. Every `@phpstan-ignore` suppression is a type-safety hole.

**Before adding a suppression, try these alternatives in order:**

1. **Fix with proper type hints** — most errors are fixable with correct annotations (see the PHPStan rules in CLAUDE.md, PHP Conventions).
2. **Write a PHPStan stub** — for framework limitations (dynamic properties, magic methods, extension-provided fields), add a stub file in `phpstan/stubs/` and list it under `stubFiles:` (or `scanFiles:`) in `.docker/app/phpstan.neon.dist` (the PHP 8.5 config includes it) — an unlisted stub does nothing. Stubs are the preferred solution because they teach PHPStan about the type system rather than hiding errors. See `phpstan/stubs/AdminController.stub` for an example.
3. **Only as last resort**: If neither proper types nor a stub can resolve the error, add `@phpstan-ignore` with a justification comment on the same or preceding line explaining **why** no better option exists.

**Required format when suppression is truly necessary:**
```php
$image = $this->getOwner()->MediaImage(); // @phpstan-ignore method.notFound (has_one added by extension)
```

Existing justified suppressions (`src/Extensions/MediaExtension.php`, `src/Value/NodeRef.php`, `src/Forms/GridEditorField.php:73`) carry the identifier plus a parenthesised reason. Prefer `@phpstan-ignore <identifier>` over `@phpstan-ignore-line`, which takes no identifier and silences every error on the line (`GridEditorField.php:175` is the one remaining).
