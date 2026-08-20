---
description: Code style conventions for indentation, encoding, and line endings
applyTo: "**/*"
---

# Code Style

- 4 spaces: PHP, `composer.json`
- 2 spaces: YML, JS, TS, TSX, JSON, CSS (enforced via `.editorconfig`)
- LF line endings, UTF-8, trailing newline

## CSS

- Biome lints and formats CSS; there is no Stylelint. `npm run lint` covers `client/src/styles/**/*.css`.
- Raw hex colours: `tokens.css` only. Everywhere else `noHexColors` errors — use a `--ssgrid-color-*` token.
- Biome has no selector-naming or nesting-depth rule. Flat kebab-case (`ssgrid-block-part`, no BEM `__`/`--`) is unenforced — honour it by hand.
- `nursery/noUnusedClasses` stays off: it scans HTML/JSX only, and these classes are also used from `.ss` templates.
