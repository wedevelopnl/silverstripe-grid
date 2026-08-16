import { describe, expect, it } from 'vitest'
import { viewportKey } from '@/testing/factories'
import type { AdapterConfig } from '@/types/adapter'
import type { GridSettings } from '@/types/elements'
import {
  formatOffsetLabel,
  formatWidthLabel,
  getColumnCount,
  getDefaultViewport,
  getOffsetOptions,
  getOffsetStartLine,
  getOffsetStrategy,
  getViewports,
  getWidthOptions,
  resolveViewportSettings,
} from './gridAdapter'

describe('getViewports', () => {
  it('returns viewport configs from adapter config', () => {
    const viewports = getViewports()
    expect(viewports).toHaveLength(6)
    expect(viewports[0]).toEqual({ key: 'xs', label: 'Extra small', minWidth: 0 })
    expect(viewports[2]).toEqual({ key: 'md', label: 'Medium', minWidth: 768 })
  })
})

describe('getDefaultViewport', () => {
  it('returns the default viewport key', () => {
    expect(getDefaultViewport()).toBe('md')
  })
})

describe('getColumnCount', () => {
  it('returns the column count', () => {
    expect(getColumnCount()).toBe(12)
  })

  it('reads the live CMS config, so a later config replacement is observed', () => {
    expect(getColumnCount()).toBe(12)

    const current = window.ss!.config.sections[0].gridAdapter as AdapterConfig
    window.ss!.config.sections[0].gridAdapter = { ...current, columnCount: 6 }

    expect(getColumnCount()).toBe(6)
  })
})

describe('getOffsetStrategy', () => {
  it('returns the offset strategy', () => {
    expect(getOffsetStrategy()).toBe('margin')
  })
})

describe('getWidthOptions', () => {
  it('generates options from 1 to columnCount plus hidden', () => {
    const options = getWidthOptions()
    expect(options).toHaveLength(13) // 12 widths + hidden
    expect(options[0]).toEqual({ value: 1, label: '1 column' })
    expect(options[11]).toEqual({ value: 12, label: '12 columns' })
    expect(options[12]).toEqual({ value: 'hidden', label: 'hidden' })
  })
})

describe('getOffsetOptions', () => {
  it('generates options from 0 to columnCount - 1 when no width given', () => {
    const options = getOffsetOptions()
    expect(options).toHaveLength(12) // 0 to 11
    expect(options[0]).toEqual({ value: 0, label: 'Offset 0' })
    expect(options[1]).toEqual({ value: 1, label: 'Offset 1' })
  })

  it('limits max offset based on current width', () => {
    const options = getOffsetOptions(10)
    expect(options).toHaveLength(3) // 0, 1, 2
    expect(options[2]).toEqual({ value: 2, label: 'Offset 2' })
  })
})

describe('resolveViewportSettings', () => {
  const settings: GridSettings = {
    default: { width: 12, offset: 0, visible: true },
    overrides: {
      sm: { width: 6, offset: 3, visible: true },
    },
  }

  it('returns override settings when viewport has an override', () => {
    expect(resolveViewportSettings(settings, viewportKey('sm'))).toEqual({
      width: 6,
      offset: 3,
      visible: true,
    })
  })

  it('returns default settings when viewport has no override', () => {
    expect(resolveViewportSettings(settings, viewportKey('lg'))).toEqual({
      width: 12,
      offset: 0,
      visible: true,
    })
  })
})

describe('formatWidthLabel()', () => {
  it('uses the singular for a one-column span', () => {
    expect(formatWidthLabel(1)).toBe('1 column')
  })

  it('uses the plural for every wider span', () => {
    expect(formatWidthLabel(2)).toBe('2 columns')
    expect(formatWidthLabel(12)).toBe('12 columns')
  })
})

describe('formatOffsetLabel()', () => {
  function useStrategy(strategy: 'margin' | 'grid-placement') {
    window.ss!.config.sections[0].gridAdapter!.offsetStrategy = strategy
  }

  it('labels a margin-strategy offset as an offset', () => {
    useStrategy('margin')
    expect(formatOffsetLabel(0)).toBe('Offset 0')
    expect(formatOffsetLabel(2)).toBe('Offset 2')
  })

  it('labels a grid-placement offset by the line the column starts on', () => {
    // Tailwind emits `col-start-N`, so offset 2 starts at line 3. Calling that
    // "Offset 2" would contradict the class the adapter generates.
    useStrategy('grid-placement')
    expect(formatOffsetLabel(0)).toBe('Start 1')
    expect(formatOffsetLabel(2)).toBe('Start 3')
  })

  it('does not pluralise either form, so zero keeps the same shape', () => {
    useStrategy('margin')
    expect(formatOffsetLabel(1)).toBe('Offset 1')
    expect(formatOffsetLabel(5)).toBe('Offset 5')
  })
})

describe('getOffsetStartLine()', () => {
  it('is 1-based, matching the col-start-N the grid adapter emits', () => {
    expect(getOffsetStartLine(0)).toBe(1)
    expect(getOffsetStartLine(2)).toBe(3)
  })
})
