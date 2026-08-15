// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { check, collectDefinitions, collectReferences, isExcluded } from './check-icon-classes.mjs'

describe('collectReferences()', () => {
  it('finds icon classes in JSX attributes and string literals', () => {
    const source = `
      <span className="ssgrid-icon-button__glyph font-icon-dot-3" />
      const glyph = 'font-icon-back-in-time'
      return \`ssgrid-viewport-switcher__icon font-icon-mobile\`
    `
    expect(collectReferences(source)).toEqual(
      new Set(['font-icon-dot-3', 'font-icon-back-in-time', 'font-icon-mobile']),
    )
  })

  it('ignores a dynamic prefix so interpolated names are not reported', () => {
    // An interpolated name (as in testing/factories.ts) must not register a
    // bare `font-icon-`: that class is never rendered, so reporting it would
    // be an unfixable false positive. Built by concatenation to keep the
    // literal `${...}` out of this file's own source.
    const interpolated = ['icon: `font-icon-$', '{typeName.toLowerCase()}`'].join('')
    expect(collectReferences(interpolated)).toEqual(new Set())
  })

  it('returns an empty set for source with no icons', () => {
    expect(collectReferences('const x = 1')).toEqual(new Set())
  })
})

describe('collectDefinitions()', () => {
  it('reads the class names off :before rules in the icon font stylesheet', () => {
    const css = '.font-icon-dot-3:before{content:"9"}.font-icon-trash-bin:before{content:"x"}'
    expect(collectDefinitions(css)).toEqual(new Set(['font-icon-dot-3', 'font-icon-trash-bin']))
  })

  it('ignores classes that merely mention the prefix without defining a glyph', () => {
    expect(collectDefinitions('.font-icon-dot-3{color:red}')).toEqual(new Set())
  })
})

describe('isExcluded()', () => {
  it.each([
    ['client/src/js/components/Foo/Foo.test.tsx', true],
    ['client/src/js/components/Foo/Foo.spec.ts', true],
    ['client/src/js/testing/factories.ts', true],
    ['client/src/js/components/Foo/Foo.tsx', false],
    ['client/src/js/utils/gridAdapter.ts', false],
  ])('%s -> %s', (path, expected) => {
    expect(isExcluded(path)).toBe(expected)
  })

  it('normalises Windows separators before matching', () => {
    expect(isExcluded('client\\src\\js\\testing\\factories.ts')).toBe(true)
  })
})

describe('check()', () => {
  it('passes when every referenced class is defined', () => {
    expect(
      check(new Set(['font-icon-dot-3']), new Set(['font-icon-dot-3', 'font-icon-x'])),
    ).toEqual([])
  })

  it('reports a class the icon font does not define', () => {
    // The real regression: `-h` suffix that never existed.
    const errors = check(new Set(['font-icon-dot-3-h']), new Set(['font-icon-dot-3']))
    expect(errors[0]).toBe('1 icon class(es) are not defined by the admin icon font:')
    expect(errors[1]).toBe('  - font-icon-dot-3-h')
  })

  it('lists every missing class, sorted', () => {
    const errors = check(new Set(['font-icon-zeta', 'font-icon-alpha']), new Set())
    expect(errors.slice(0, 3)).toEqual([
      '2 icon class(es) are not defined by the admin icon font:',
      '  - font-icon-alpha',
      '  - font-icon-zeta',
    ])
  })

  it('passes when nothing is referenced at all', () => {
    expect(check(new Set(), new Set())).toEqual([])
  })
})
