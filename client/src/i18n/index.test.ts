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

  it('passes the _t result through ss.i18n.inject when params are provided', () => {
    mockT.mockReturnValue('Hallo {name}')
    mockInject.mockReturnValue('Hallo Erik')
    const result = t('WeDevelopGrid.Test.GREETING', 'Hello {name}', { name: 'Erik' })
    expect(mockT).toHaveBeenCalledWith('WeDevelopGrid.Test.GREETING', 'Hello {name}')
    expect(mockInject).toHaveBeenCalledWith('Hallo {name}', { name: 'Erik' })
    expect(result).toBe('Hallo Erik')
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
