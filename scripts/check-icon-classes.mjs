#!/usr/bin/env node
// Enforces: every `font-icon-*` class hardcoded in production frontend source
// is actually defined by the SilverStripe admin icon font.
//
// A typo'd icon class fails silently — the element keeps its box, its testid
// and its click handler, and renders no glyph. `font-icon-dot-3-h` (which does
// not exist; the real class is `font-icon-dot-3`) shipped exactly that way and
// left every ActionsMenu trigger invisible, so columns looked like they had no
// actions at all. Per-component className assertions would catch it, but the
// codebase treats glyph names as visual-only and deliberately does not assert
// on them; this one check covers every icon instead, and also catches an admin
// upgrade renaming or dropping a glyph.
//
// Icon classes that arrive as DATA (the element-type registry's `icon` field,
// which a project defines against its own font) are out of scope — hence the
// test/testing exclusions below.
//
// Exits non-zero with one line per offending class on failure.

import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(import.meta.dirname, '..')
const SOURCE_DIR = join(ROOT, 'client/src/js')
const ICON_FONT_CSS = join(ROOT, 'vendor/silverstripe/admin/client/dist/styles/bundle.css')

/** Trailing `[a-z0-9]` keeps a dynamic prefix like `font-icon-${x}` out of the set. */
const REFERENCE_PATTERN = /font-icon-[a-z0-9]+(?:-[a-z0-9]+)*/g
const DEFINITION_PATTERN = /\.(font-icon-[a-z0-9-]+):before/g

/**
 * Source that describes server-provided icons rather than ones we hardcode.
 *
 * The `testing/` match is on a path segment rather than the full prefix so it
 * holds for both the absolute paths `walk()` produces and relative ones — only
 * `client/src/js` is ever walked, so there is no other `testing/` to catch.
 */
export function isExcluded(path) {
  const unix = path.replaceAll('\\', '/')
  return /\.(test|spec)\.[jt]sx?$/.test(unix) || /(^|\/)testing\//.test(unix)
}

export function collectReferences(source) {
  return new Set(source.match(REFERENCE_PATTERN) ?? [])
}

export function collectDefinitions(css) {
  return new Set([...css.matchAll(DEFINITION_PATTERN)].map((m) => m[1]))
}

export function check(referenced, defined) {
  const missing = [...referenced].filter((c) => !defined.has(c)).sort((a, b) => a.localeCompare(b))
  if (missing.length === 0) return []

  return [
    `${missing.length} icon class(es) are not defined by the admin icon font:`,
    ...missing.map((c) => `  - ${c}`),
    'These render an empty box. Check the spelling against the classes in',
    'vendor/silverstripe/admin/client/dist/styles/bundle.css.',
  ]
}

function* walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) yield* walk(path)
    else if (/\.[jt]sx?$/.test(entry.name) && !isExcluded(path)) yield path
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  if (!existsSync(ICON_FONT_CSS)) {
    console.error('[check-icon-classes] FAIL')
    console.error(`Admin icon font stylesheet not found at ${ICON_FONT_CSS}.`)
    console.error('Run `composer install` on the host first — the JS pipeline reads vendor/.')
    process.exit(1)
  }

  const referenced = new Set()
  for (const file of walk(SOURCE_DIR)) {
    for (const cls of collectReferences(readFileSync(file, 'utf8'))) referenced.add(cls)
  }

  const errors = check(referenced, collectDefinitions(readFileSync(ICON_FONT_CSS, 'utf8')))
  if (errors.length > 0) {
    console.error('[check-icon-classes] FAIL')
    for (const e of errors) console.error(e)
    process.exit(1)
  }

  console.log(`[check-icon-classes] OK — ${referenced.size} icon class(es) all defined`)
}
