import { describe, expect, it } from 'vitest'
import { createColumnNode, createRowNode, createSectionNode } from '@/testing/factories'
import type { ElementNode, ViewportSettings } from '@/types/elements'
import { countOverrides } from './countOverrides'

const override: ViewportSettings = { width: 6, offset: 0, visible: true }

describe('countOverrides', () => {
  it('returns an empty object for an empty tree', () => {
    expect(countOverrides([])).toEqual({})
  })

  it('returns an empty object when no column has overrides', () => {
    const section = createSectionNode()
    expect(countOverrides([section])).toEqual({})
  })

  it('counts a single viewport override', () => {
    const column = createColumnNode({
      gridSettings: {
        default: { width: 12, offset: 0, visible: true },
        overrides: { lg: override },
      },
    })
    const row = createRowNode({ children: [column] })
    const section = createSectionNode({ children: [row] })

    expect(countOverrides([section])).toEqual({ _total: 1, lg: 1 })
  })

  it('counts multiple viewport overrides on one column', () => {
    const column = createColumnNode({
      gridSettings: {
        default: { width: 12, offset: 0, visible: true },
        overrides: { md: override, lg: override, xl: override },
      },
    })
    const row = createRowNode({ children: [column] })
    const section = createSectionNode({ children: [row] })

    expect(countOverrides([section])).toEqual({ _total: 1, md: 1, lg: 1, xl: 1 })
  })

  it('aggregates across columns in the same row', () => {
    const a = createColumnNode({
      gridSettings: {
        default: { width: 6, offset: 0, visible: true },
        overrides: { md: override, lg: override },
      },
    })
    const b = createColumnNode({
      gridSettings: {
        default: { width: 6, offset: 0, visible: true },
        overrides: { lg: override },
      },
    })
    const row = createRowNode({ children: [a, b] })
    const section = createSectionNode({ children: [row] })

    expect(countOverrides([section])).toEqual({ _total: 2, md: 1, lg: 2 })
  })

  it('aggregates across multiple sections and rows', () => {
    const column1 = createColumnNode({
      gridSettings: {
        default: { width: 12, offset: 0, visible: true },
        overrides: { lg: override },
      },
    })
    const column2 = createColumnNode({
      gridSettings: {
        default: { width: 12, offset: 0, visible: true },
        overrides: { md: override, lg: override },
      },
    })
    const section1 = createSectionNode({
      children: [createRowNode({ children: [column1] })],
    })
    const section2 = createSectionNode({
      children: [createRowNode({ children: [column2] })],
    })

    expect(countOverrides([section1, section2])).toEqual({ _total: 2, md: 1, lg: 2 })
  })

  it('does not count columns with no overrides even when siblings have some', () => {
    const withOverride = createColumnNode({
      gridSettings: {
        default: { width: 6, offset: 0, visible: true },
        overrides: { lg: override },
      },
    })
    const withoutOverride = createColumnNode({
      gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
    })
    const row = createRowNode({ children: [withOverride, withoutOverride] })
    const section = createSectionNode({ children: [row] })

    expect(countOverrides([section])).toEqual({ _total: 1, lg: 1 })
  })

  it('tolerates null children on containers', () => {
    const section: ElementNode = {
      ...createSectionNode(),
      children: null,
    }
    expect(countOverrides([section])).toEqual({})
  })
})
