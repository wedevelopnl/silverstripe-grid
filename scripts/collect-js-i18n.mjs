#!/usr/bin/env node
// Collects t(key, fallback, params?) calls from client/src/js/** and emits
// client/lang/src/{en,nl}.json + client/lang/{en,nl}.js runtime bundles.
//
// Hard errors:
//   - dynamic key/fallback (template literal, identifier, expression)
//   - key not starting with WeDevelopGrid.
//   - duplicate key with conflicting fallback
//   - key present in en.json but no longer in source

import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const ROOT = resolve(import.meta.dirname, '..')
const SRC = join(ROOT, 'client/src/js')
const LANG_SRC_EN = join(ROOT, 'client/lang/src/en.json')
const LANG_SRC_NL = join(ROOT, 'client/lang/src/nl.json')
const LANG_RUNTIME_EN = join(ROOT, 'client/lang/en.js')
const LANG_RUNTIME_NL = join(ROOT, 'client/lang/nl.js')
export const KEY_PREFIX = 'WeDevelopGrid.'
export const I18N_MODULE_SUFFIX = 'i18n'

function walk(dir) {
  const out = []
  for (const name of readdirSync(dir)) {
    if (name === 'testing') continue
    const full = join(dir, name)
    const stat = statSync(full)
    if (stat.isDirectory()) {
      out.push(...walk(full))
    } else if (
      (name.endsWith('.ts') || name.endsWith('.tsx')) &&
      !name.endsWith('.test.ts') &&
      !name.endsWith('.test.tsx')
    ) {
      out.push(full)
    }
  }
  return out
}

export function isI18nImport(modulePath) {
  return (
    modulePath.endsWith(`/${I18N_MODULE_SUFFIX}`) ||
    modulePath === I18N_MODULE_SUFFIX ||
    modulePath.endsWith(`/${I18N_MODULE_SUFFIX}/index`)
  )
}

export function collect({ srcRoot = SRC, langSrcEnPath = LANG_SRC_EN } = {}) {
  const errors = []
  const found = new Map()

  for (const file of walk(srcRoot)) {
    const source = readFileSync(file, 'utf8')
    const sf = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)

    const tIdents = new Set()
    sf.statements.forEach((stmt) => {
      if (!ts.isImportDeclaration(stmt)) return
      const mod = stmt.moduleSpecifier
      if (!ts.isStringLiteral(mod)) return
      if (!isI18nImport(mod.text)) return
      const clause = stmt.importClause
      if (!clause?.namedBindings || !ts.isNamedImports(clause.namedBindings)) return
      for (const elem of clause.namedBindings.elements) {
        if ((elem.propertyName?.text ?? elem.name.text) === 't') {
          tIdents.add(elem.name.text)
        }
      }
    })

    if (tIdents.size === 0) continue

    const visit = (node) => {
      if (
        ts.isCallExpression(node) &&
        ts.isIdentifier(node.expression) &&
        tIdents.has(node.expression.text)
      ) {
        const [keyArg, fallbackArg] = node.arguments
        const { line } = sf.getLineAndCharacterOfPosition(node.getStart())
        const loc = `${relative(ROOT, file)}:${line + 1}`

        if (!keyArg || !ts.isStringLiteral(keyArg)) {
          errors.push(`${loc}: t() requires a static string literal as first argument`)
        } else if (!fallbackArg || !ts.isStringLiteral(fallbackArg)) {
          errors.push(`${loc}: t() requires a static string literal as second argument (fallback)`)
        } else {
          const key = keyArg.text
          const fallback = fallbackArg.text
          if (!key.startsWith(KEY_PREFIX)) {
            errors.push(`${loc}: key ${JSON.stringify(key)} must start with "${KEY_PREFIX}"`)
          } else {
            const existing = found.get(key)
            if (existing && existing.fallback !== fallback) {
              errors.push(
                `${loc}: key ${JSON.stringify(key)} has conflicting fallback (also at ${existing.location})`,
              )
            } else if (!existing) {
              found.set(key, { fallback, location: loc })
            }
          }
        }
      }
      ts.forEachChild(node, visit)
    }
    visit(sf)
  }

  let existingEn = {}
  try {
    existingEn = JSON.parse(readFileSync(langSrcEnPath, 'utf8') || '{}')
  } catch {
    // missing or invalid — treat as empty
  }
  for (const key of Object.keys(existingEn)) {
    if (!found.has(key)) {
      errors.push(
        `client/lang/src/en.json: key ${JSON.stringify(key)} no longer exists in source — remove it`,
      )
    }
  }

  return { errors, found }
}

export function emit(
  found,
  {
    langSrcEnPath = LANG_SRC_EN,
    langSrcNlPath = LANG_SRC_NL,
    langRuntimeEnPath = LANG_RUNTIME_EN,
    langRuntimeNlPath = LANG_RUNTIME_NL,
  } = {},
) {
  const sortedEnEntries = [...found.entries()].sort(([a], [b]) => a.localeCompare(b))
  const sortedEn = Object.fromEntries(sortedEnEntries.map(([k, v]) => [k, v.fallback]))
  writeFileSync(langSrcEnPath, `${JSON.stringify(sortedEn, null, 2)}\n`)

  const nlRaw = readFileSync(langSrcNlPath, 'utf8') || '{}'
  const nl = JSON.parse(nlRaw)
  const sortedNl = Object.fromEntries(
    Object.keys(nl)
      .sort((a, b) => a.localeCompare(b))
      .map((k) => [k, nl[k]]),
  )
  writeFileSync(langSrcNlPath, `${JSON.stringify(sortedNl, null, 2)}\n`)

  const banner = '// Generated by scripts/collect-js-i18n.mjs — do not edit by hand'
  const enBundle = `${banner}\nif (typeof window !== "undefined" && window.ss && window.ss.i18n) {\n  window.ss.i18n.addDictionary("en", ${JSON.stringify(sortedEn)});\n}\n`
  const nlBundle = `${banner}\nif (typeof window !== "undefined" && window.ss && window.ss.i18n) {\n  window.ss.i18n.addDictionary("nl", ${JSON.stringify(sortedNl)});\n}\n`
  writeFileSync(langRuntimeEnPath, enBundle)
  writeFileSync(langRuntimeNlPath, nlBundle)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2)
  const dryRun = args.includes('--dry-run')

  const { errors, found } = collect()
  if (errors.length > 0) {
    console.error('[collect-js-i18n] errors:')
    for (const e of errors) console.error(`  ${e}`)
    process.exit(1)
  }

  if (dryRun) {
    console.log(`[collect-js-i18n] OK — ${found.size} keys (dry run, no files written)`)
    process.exit(0)
  }

  emit(found)
  console.log(
    `[collect-js-i18n] wrote ${found.size} keys to client/lang/src/en.json + runtime bundles`,
  )
}
