import { beforeEach, describe, expect, it } from 'vitest'
import { viewportKey } from '@/testing/factories'
import type { AdapterConfig } from '@/types/adapter'
import type { GridSettings } from '@/types/elements'
import {
  getColumnCount,
  getDefaultViewport,
  getOffsetClass,
  getOffsetOptions,
  getOffsetStrategy,
  getRowClasses,
  getViewports,
  getWidthClass,
  getWidthOptions,
  resetAdapterCache,
  resolveViewportSettings,
} from './gridAdapter'

beforeEach(() => {
  resetAdapterCache()
})

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

  it('caches the adapter config: a later config replacement is not re-fetched', () => {
    // First read resolves and caches the adapter config object (columnCount 12).
    expect(getColumnCount()).toBe(12)

    // Swap in a brand-new adapter config object on the live CMS config. Because
    // config() only fetches when its cache is empty, the cached reference is
    // kept and the replacement is NOT observed.
    const current = window.ss!.config.sections[0].gridAdapter as AdapterConfig
    const replacement: AdapterConfig = { ...current, columnCount: 6 }
    window.ss!.config.sections[0].gridAdapter = replacement

    expect(getColumnCount()).toBe(12)
  })
})

describe('getRowClasses', () => {
  it('returns the row CSS classes', () => {
    expect(getRowClasses()).toBe('row')
  })
})

describe('getOffsetStrategy', () => {
  it('returns the offset strategy', () => {
    expect(getOffsetStrategy()).toBe('margin')
  })
})

describe('getWidthClass', () => {
  it('returns CSS class for a valid width', () => {
    expect(getWidthClass(6)).toBe('col-6')
  })

  it('returns empty string for unmapped width', () => {
    expect(getWidthClass(99)).toBe('')
  })
})

describe('getOffsetClass', () => {
  it('returns CSS class for a valid offset', () => {
    expect(getOffsetClass(3)).toBe('offset-3')
  })

  it('returns empty string for unmapped offset', () => {
    expect(getOffsetClass(99)).toBe('')
  })
})

describe('getWidthOptions', () => {
  it('generates options from 1 to columnCount plus hidden', () => {
    const options = getWidthOptions()
    expect(options).toHaveLength(13) // 12 widths + hidden
    expect(options[0]).toEqual({ value: 1, label: '1/12' })
    expect(options[11]).toEqual({ value: 12, label: '12/12' })
    expect(options[12]).toEqual({ value: 'hidden', label: 'hidden' })
  })

  it('translates the hidden label at call time, not first-call time', () => {
    // getWidthOptions used to bake t('…HIDDEN') into a module-level cache: a
    // first call before ss.i18n had loaded its lang files pinned the English
    // fallback for the whole session. getOffsetOptions already translated per
    // call — this pins the consistent per-call behavior.
    const originalSs = window.ss
    getWidthOptions() // first call happens before the "lang files load"

    window.ss = {
      ...originalSs,
      i18n: {
        _t: (key: string, fallback: string) =>
          key === 'WeDevelopGrid.GridSettings.HIDDEN' ? 'verborgen' : fallback,
        inject: (str: string) => str,
      },
    } as typeof window.ss

    try {
      const options = getWidthOptions()
      expect(options[12]).toEqual({ value: 'hidden', label: 'verborgen' })
    } finally {
      window.ss = originalSs
    }
  })
})

describe('getOffsetOptions', () => {
  it('generates options from 0 to columnCount - 1 when no width given', () => {
    const options = getOffsetOptions()
    expect(options).toHaveLength(12) // 0 to 11
    expect(options[0]).toEqual({ value: 0, label: 'none' })
    expect(options[1]).toEqual({ value: 1, label: '+1' })
  })

  it('limits max offset based on current width', () => {
    const options = getOffsetOptions(10)
    expect(options).toHaveLength(3) // 0, 1, 2
    expect(options[2]).toEqual({ value: 2, label: '+2' })
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
