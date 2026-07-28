import { describe, expect, it } from 'vitest'
import * as v from 'valibot'
import { elementNodeWireSchema, treeApiResponseWireSchema, viewportSettingsSchema } from './schemas'

/**
 * Minimal valid wire payload for a leaf (non-container) element, matching
 * `baseFieldsWireSchema`. Tests spread over this and override single fields.
 */
const baseLeaf = {
  self: { type: 'element', id: 1 },
  parent: { type: 'column', id: 2 },
  title: 'Leaf',
  blockSchema: { typeName: 'Foo', label: 'Foo', icon: 'icon', type: 'Foo', title: 'Foo' },
  obsoleteClassName: null,
  version: 1,
  canDelete: true,
  canPublish: true,
  canUnpublish: true,
  canCreate: true,
  editLink: null,
  status: 'draft',
} as const

const allowedTypeInfo = { label: 'L', icon: 'i', description: 'd' }

describe('viewportSettingsSchema', () => {
  const base = { width: 6, offset: 0, visible: true }

  it('accepts a valid positive width with a non-negative offset', () => {
    const result = v.safeParse(viewportSettingsSchema, base)
    expect(result.success).toBe(true)
  })

  it('accepts a zero offset (int<0, max> domain)', () => {
    expect(v.safeParse(viewportSettingsSchema, { ...base, offset: 0 }).success).toBe(true)
  })

  describe('width', () => {
    it.each([
      ['NaN', Number.NaN],
      ['negative', -1],
      ['zero', 0],
      ['fractional', 1.5],
    ])('rejects a %s width', (_label, width) => {
      expect(v.safeParse(viewportSettingsSchema, { ...base, width }).success).toBe(false)
    })
  })

  describe('offset', () => {
    it.each([
      ['NaN', Number.NaN],
      ['negative', -1],
      ['fractional', 1.5],
    ])('rejects a %s offset', (_label, offset) => {
      expect(v.safeParse(viewportSettingsSchema, { ...base, offset }).success).toBe(false)
    })
  })
})

describe('elementNodeWireSchema status enum', () => {
  it.each(['draft', 'published', 'modified', 'removed'])('accepts the %s status', (status) => {
    expect(v.safeParse(elementNodeWireSchema, { ...baseLeaf, status }).success).toBe(true)
  })

  it('rejects a status outside the enum', () => {
    expect(v.safeParse(elementNodeWireSchema, { ...baseLeaf, status: 'archived' }).success).toBe(
      false,
    )
  })
})

describe('elementNodeWireSchema summary', () => {
  it('accepts a multi-character summary', () => {
    const result = v.safeParse(elementNodeWireSchema, { ...baseLeaf, summary: 'A longer summary' })
    expect(result.success).toBe(true)
    expect(result.success && 'summary' in result.output && result.output.summary).toBe(
      'A longer summary',
    )
  })

  it('rejects an empty-string summary (min length 1)', () => {
    expect(v.safeParse(elementNodeWireSchema, { ...baseLeaf, summary: '' }).success).toBe(false)
  })

  it('accepts a node with no summary (optional)', () => {
    expect(v.safeParse(elementNodeWireSchema, baseLeaf).success).toBe(true)
  })
})

describe('elementNodeWireSchema leaf discriminant', () => {
  it('rejects a leaf-shaped node carrying a container type without container fields', () => {
    // A node with containerType set must satisfy a container variant (which
    // requires children). With only base fields it must NOT
    // fall through to the leaf (undefined-containerType) variant.
    expect(
      v.safeParse(elementNodeWireSchema, { ...baseLeaf, containerType: 'section' }).success,
    ).toBe(false)
  })

  it('accepts an explicit undefined containerType on a leaf', () => {
    expect(
      v.safeParse(elementNodeWireSchema, { ...baseLeaf, containerType: undefined }).success,
    ).toBe(true)
  })
})

describe('editLink scheme validation', () => {
  it.each([
    ['relative CMS path', '/admin/pages/edit/show/5'],
    ['https absolute URL', 'https://example.com/edit'],
    ['http absolute URL', 'http://example.com/edit'],
  ])('accepts a %s', (_label, editLink) => {
    expect(v.safeParse(elementNodeWireSchema, { ...baseLeaf, editLink }).success).toBe(true)
  })

  it('accepts a null editLink', () => {
    expect(v.safeParse(elementNodeWireSchema, { ...baseLeaf, editLink: null }).success).toBe(true)
  })

  it.each([
    ['javascript: scheme', 'javascript:alert(1)'],
    ['data: scheme', 'data:text/html,<script>alert(1)</script>'],
    ['vbscript: scheme', 'vbscript:msgbox(1)'],
    ['protocol-relative URL', '//evil.example.com/edit'],
    ['bare token', 'not-a-url'],
    ['backslash open redirect', '/\\evil.com'],
    ['double backslash open redirect', '/\\\\evil.com'],
    ['mixed-case javascript', 'JavaScript:alert(1)'],
    ['uppercase data', 'DATA:text/html,x'],
    ['tab-injected open redirect', '/\t/evil.com'],
    ['newline-injected open redirect', '/\n/evil.com'],
    ['carriage-return-injected open redirect', '/\r/evil.com'],
  ])('rejects a %s', (_label, editLink) => {
    expect(v.safeParse(elementNodeWireSchema, { ...baseLeaf, editLink }).success).toBe(false)
  })

  it('reports the exact validation message for an unsafe editLink', () => {
    const result = v.safeParse(elementNodeWireSchema, {
      ...baseLeaf,
      editLink: 'javascript:alert(1)',
    })
    expect(result.success).toBe(false)
    expect(result.success === false && v.flatten(result.issues).nested?.editLink).toEqual([
      'editLink must be a relative path or an http(s) URL',
    ])
  })
})

describe('tree-root allowedTypes (allowedTypeInfoSchema + emptyArrayToObject)', () => {
  // Allowed child types live once per container type at the TREE ROOT — the
  // per-node field no longer exists on the wire.
  const treeWith = (allowedTypes: unknown) => ({
    rootParent: { type: 'page', id: 1 },
    allowedTypes,
    nodes: [],
  })

  it('parses a populated per-type map and preserves entries', () => {
    const result = v.safeParse(
      treeApiResponseWireSchema,
      treeWith({ section: { Foo: allowedTypeInfo }, row: {}, column: {} }),
    )
    expect(result.success).toBe(true)
    expect(result.success && result.output.allowedTypes.section).toEqual({
      Foo: allowedTypeInfo,
    })
  })

  it('coerces a PHP empty-array sentinel to an empty object per type', () => {
    const result = v.safeParse(
      treeApiResponseWireSchema,
      treeWith({ section: [], row: [], column: [] }),
    )
    expect(result.success).toBe(true)
    expect(result.success && result.output.allowedTypes.column).toEqual({})
  })

  it('rejects a non-empty array of allowed types (stays an array, not a record)', () => {
    expect(
      v.safeParse(
        treeApiResponseWireSchema,
        treeWith({ section: [allowedTypeInfo], row: {}, column: {} }),
      ).success,
    ).toBe(false)
  })

  it('requires label, icon and description on each allowed-type entry', () => {
    expect(
      v.safeParse(
        treeApiResponseWireSchema,
        treeWith({ section: { Foo: { label: 'L' } }, row: {}, column: {} }),
      ).success,
    ).toBe(false)
  })

  it('rejects a tree response missing the root allowedTypes map', () => {
    expect(
      v.safeParse(treeApiResponseWireSchema, { rootParent: { type: 'page', id: 1 }, nodes: [] })
        .success,
    ).toBe(false)
  })

  it('requires all three container-type keys', () => {
    expect(v.safeParse(treeApiResponseWireSchema, treeWith({ section: {}, row: {} })).success).toBe(
      false,
    )
  })

  it('rejects a container node still carrying the removed per-node allowedTypes as a leaf mismatch', () => {
    // The per-node field was removed from the wire: valibot's v.object strips
    // unknown keys, so a legacy payload still parses — but the map must come
    // from the root, never the node. This pins that the node-level key has no
    // effect on the output.
    const legacyNode = {
      ...baseLeaf,
      containerType: 'section',
      allowedTypes: { Foo: allowedTypeInfo },
      children: null,
    }
    const result = v.safeParse(elementNodeWireSchema, legacyNode)
    expect(result.success).toBe(true)
    expect(result.success && 'allowedTypes' in result.output).toBe(false)
  })
})

describe('extensions (object record, never an array)', () => {
  it('accepts an object-valued extensions map', () => {
    const result = v.safeParse(elementNodeWireSchema, { ...baseLeaf, extensions: { fluent: true } })
    expect(result.success).toBe(true)
    expect(result.success && 'extensions' in result.output && result.output.extensions).toEqual({
      fluent: true,
    })
  })

  it('accepts a node with no extensions (optional)', () => {
    expect(v.safeParse(elementNodeWireSchema, baseLeaf).success).toBe(true)
  })

  it.each([
    ['a non-empty array', [1]],
    ['an empty array', []],
  ])('rejects %s (parity with zod z.record, which rejects all arrays)', (_label, extensions) => {
    expect(v.safeParse(elementNodeWireSchema, { ...baseLeaf, extensions }).success).toBe(false)
  })
})
