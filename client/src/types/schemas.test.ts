import { describe, expect, it } from 'vitest'
import { elementNodeWireSchema, viewportSettingsSchema } from './schemas'

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
    const result = viewportSettingsSchema.safeParse(base)
    expect(result.success).toBe(true)
  })

  it('accepts a zero offset (int<0, max> domain)', () => {
    expect(viewportSettingsSchema.safeParse({ ...base, offset: 0 }).success).toBe(true)
  })

  describe('width', () => {
    it.each([
      ['NaN', Number.NaN],
      ['negative', -1],
      ['zero', 0],
      ['fractional', 1.5],
    ])('rejects a %s width', (_label, width) => {
      expect(viewportSettingsSchema.safeParse({ ...base, width }).success).toBe(false)
    })
  })

  describe('offset', () => {
    it.each([
      ['NaN', Number.NaN],
      ['negative', -1],
      ['fractional', 1.5],
    ])('rejects a %s offset', (_label, offset) => {
      expect(viewportSettingsSchema.safeParse({ ...base, offset }).success).toBe(false)
    })
  })
})

describe('elementNodeWireSchema status enum', () => {
  it.each(['draft', 'published', 'modified', 'removed'])('accepts the %s status', (status) => {
    expect(elementNodeWireSchema.safeParse({ ...baseLeaf, status }).success).toBe(true)
  })

  it('rejects a status outside the enum', () => {
    expect(elementNodeWireSchema.safeParse({ ...baseLeaf, status: 'archived' }).success).toBe(false)
  })
})

describe('elementNodeWireSchema summary', () => {
  it('accepts a multi-character summary', () => {
    const result = elementNodeWireSchema.safeParse({ ...baseLeaf, summary: 'A longer summary' })
    expect(result.success).toBe(true)
    expect(result.success && 'summary' in result.data && result.data.summary).toBe(
      'A longer summary',
    )
  })

  it('rejects an empty-string summary (min length 1)', () => {
    expect(elementNodeWireSchema.safeParse({ ...baseLeaf, summary: '' }).success).toBe(false)
  })

  it('accepts a node with no summary (optional)', () => {
    expect(elementNodeWireSchema.safeParse(baseLeaf).success).toBe(true)
  })
})

describe('elementNodeWireSchema leaf discriminant', () => {
  it('rejects a leaf-shaped node carrying a container type without container fields', () => {
    // A node with containerType set must satisfy a container variant (which
    // requires allowedTypes + children). With only base fields it must NOT
    // fall through to the leaf (undefined-containerType) variant.
    expect(elementNodeWireSchema.safeParse({ ...baseLeaf, containerType: 'section' }).success).toBe(
      false,
    )
  })

  it('accepts an explicit undefined containerType on a leaf', () => {
    expect(elementNodeWireSchema.safeParse({ ...baseLeaf, containerType: undefined }).success).toBe(
      true,
    )
  })
})

describe('section allowedTypes (allowedTypeInfoSchema + emptyArrayToObject)', () => {
  const sectionWith = (allowedTypes: unknown) => ({
    ...baseLeaf,
    containerType: 'section',
    allowedTypes,
    children: null,
  })

  it('parses a populated allowedTypes record and preserves entries', () => {
    const result = elementNodeWireSchema.safeParse(sectionWith({ Foo: allowedTypeInfo }))
    expect(result.success).toBe(true)
    expect(result.success && 'allowedTypes' in result.data && result.data.allowedTypes).toEqual({
      Foo: allowedTypeInfo,
    })
  })

  it('coerces an empty array to an empty object', () => {
    const result = elementNodeWireSchema.safeParse(sectionWith([]))
    expect(result.success).toBe(true)
    expect(result.success && 'allowedTypes' in result.data && result.data.allowedTypes).toEqual({})
  })

  it('rejects a non-empty array of allowed types (stays an array, not a record)', () => {
    expect(elementNodeWireSchema.safeParse(sectionWith([allowedTypeInfo])).success).toBe(false)
  })

  it('requires label, icon and description on each allowed-type entry', () => {
    expect(elementNodeWireSchema.safeParse(sectionWith({ Foo: { label: 'L' } })).success).toBe(
      false,
    )
  })
})
