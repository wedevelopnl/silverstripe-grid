import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { t } from './index'

describe('t()', () => {
  const mockT = vi.fn()
  const mockInject = vi.fn()

  beforeEach(() => {
    window.ss = {
      i18n: {
        _t: mockT,
        inject: mockInject,
        currentLocale: 'en',
        addDictionary: vi.fn(),
        // biome-ignore lint/suspicious/noExplicitAny: test stub
      } as any,
      // biome-ignore lint/suspicious/noExplicitAny: test stub
      config: {} as any,
    }
    mockT.mockReset()
    mockInject.mockReset()
  })

  afterEach(() => {
    // biome-ignore lint/suspicious/noExplicitAny: test cleanup
    delete (window as any).ss
  })

  it('calls ss.i18n._t with key and fallback only (no params) and returns its result', () => {
    mockT.mockReturnValue('Opslaan')
    const result = t('WeDevelopGrid.Test.SAVE', 'Save')
    expect(mockT).toHaveBeenCalledWith('WeDevelopGrid.Test.SAVE', 'Save')
    expect(mockT).toHaveBeenCalledTimes(1)
    expect(mockInject).not.toHaveBeenCalled()
    expect(result).toBe('Opslaan')
  })

  it('looks the key up via _t, then substitutes params itself', () => {
    mockT.mockReturnValue('Hallo {name}')
    const result = t('WeDevelopGrid.Test.GREETING', 'Hello {name}', { name: 'Erik' })
    expect(mockT).toHaveBeenCalledWith('WeDevelopGrid.Test.GREETING', 'Hello {name}')
    expect(result).toBe('Hallo Erik')
  })

  it('does not delegate substitution to ss.i18n.inject', () => {
    // Vendor's replacer is `map[key] ? map[key] : match`, so any falsy value
    // leaves the placeholder in the output. We keep the dictionary lookup but
    // do the substitution ourselves — see the note in index.ts.
    mockT.mockReturnValue('Hallo {name}')
    t('WeDevelopGrid.Test.GREETING', 'Hello {name}', { name: 'Erik' })
    expect(mockInject).not.toHaveBeenCalled()
  })

  it('substitutes a zero-valued param instead of leaving the placeholder', () => {
    // The regression this guards: the column offset picker rendered a literal
    // "{count} offset" for offset 0, because 0 is falsy to the vendor helper.
    mockT.mockReturnValue('{count} offset')
    expect(t('WeDevelopGrid.Test.OFFSET', '{count} offset', { count: 0 })).toBe('0 offset')
  })

  it('substitutes a zero-valued param on the no-CMS fallback path too', () => {
    // biome-ignore lint/suspicious/noExplicitAny: test cleanup
    delete (window as any).ss
    expect(t('WeDevelopGrid.Test.OFFSET', '{count} offset', { count: 0 })).toBe('0 offset')
  })

  it('returns fallback verbatim when ss.i18n is unavailable and no params provided', () => {
    // biome-ignore lint/suspicious/noExplicitAny: test cleanup
    delete (window as any).ss
    expect(t('WeDevelopGrid.Test.MISSING', 'Fallback text')).toBe('Fallback text')
  })

  it('substitutes params locally when ss.i18n is unavailable', () => {
    // biome-ignore lint/suspicious/noExplicitAny: test cleanup
    delete (window as any).ss
    expect(t('WeDevelopGrid.Test.GREETING', 'Hello {name}', { name: 'Erik' })).toBe('Hello Erik')
  })
})
