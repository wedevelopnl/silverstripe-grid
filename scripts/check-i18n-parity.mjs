#!/usr/bin/env node
// Enforces:
//   1. Bidirectional key parity between en/nl pairs (PHP YAML + JS JSON)
//   2. Non-empty NL values (after trim)
//
// Exits non-zero with one line per offending key on failure.

import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { load } from 'js-yaml'

const ROOT = resolve(import.meta.dirname, '..')

export function flattenYaml(obj, prefix = '') {
  const out = {}
  if (obj === null || typeof obj !== 'object') return out
  for (const [k, v] of Object.entries(obj)) {
    const full = prefix ? `${prefix}.${k}` : k
    if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
      Object.assign(out, flattenYaml(v, full))
    } else if (typeof v === 'string') {
      out[full] = v
    }
  }
  return out
}

export function loadYaml(path) {
  const raw = readFileSync(path, 'utf8')
  const parsed = load(raw)
  if (parsed && typeof parsed === 'object') {
    const topKeys = Object.keys(parsed)
    if (topKeys.length === 1) {
      return flattenYaml(parsed[topKeys[0]])
    }
  }
  return {}
}

export function loadJson(path) {
  const raw = readFileSync(path, 'utf8')
  return JSON.parse(raw || '{}')
}

export function check(label, en, nl) {
  const errors = []
  const enKeys = new Set(Object.keys(en))
  const nlKeys = new Set(Object.keys(nl))

  const missingInNl = [...enKeys].filter((k) => !nlKeys.has(k)).sort((a, b) => a.localeCompare(b))
  const extraInNl = [...nlKeys].filter((k) => !enKeys.has(k)).sort((a, b) => a.localeCompare(b))

  if (missingInNl.length > 0) {
    errors.push(`${label}: NL is missing ${missingInNl.length} key(s) present in EN:`)
    for (const k of missingInNl) errors.push(`  - ${k}`)
  }
  if (extraInNl.length > 0) {
    errors.push(`${label}: NL has ${extraInNl.length} orphan key(s) not in EN:`)
    for (const k of extraInNl) errors.push(`  - ${k}`)
  }

  const empty = Object.entries(nl)
    .filter(([, v]) => typeof v !== 'string' || v.trim() === '')
    .map(([k]) => k)
    .sort((a, b) => a.localeCompare(b))
  if (empty.length > 0) {
    errors.push(`${label}: NL has ${empty.length} empty value(s):`)
    for (const k of empty) errors.push(`  - ${k}`)
  }

  return errors
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const errors = [
    ...check(
      'lang/{en,nl}.yml',
      loadYaml(join(ROOT, 'lang/en.yml')),
      loadYaml(join(ROOT, 'lang/nl.yml')),
    ),
    ...check(
      'client/lang/src/{en,nl}.json',
      loadJson(join(ROOT, 'client/lang/src/en.json')),
      loadJson(join(ROOT, 'client/lang/src/nl.json')),
    ),
  ]

  if (errors.length > 0) {
    console.error('[check-i18n-parity] FAIL')
    for (const e of errors) console.error(e)
    process.exit(1)
  }

  console.log('[check-i18n-parity] OK')
}
