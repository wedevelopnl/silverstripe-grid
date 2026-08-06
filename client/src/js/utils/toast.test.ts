import { beforeEach, describe, expect, it, vi } from 'vitest'
import { showToast } from './toast'

beforeEach(() => {
  // Stub crypto.randomUUID for predictable toast IDs
  vi.spyOn(crypto, 'randomUUID').mockReturnValue('00000000-0000-0000-0000-000000000000')
})

describe('showToast', () => {
  it('dispatches a staying error DISPLAY_TOAST action when the store is available', () => {
    const dispatch = vi.fn()
    window.ss!.store = { dispatch }

    showToast('Something failed')

    expect(dispatch).toHaveBeenCalledWith({
      type: 'DISPLAY_TOAST',
      payload: {
        id: 'toast-00000000-0000-0000-0000-000000000000',
        text: 'Something failed',
        type: 'error',
        stay: true,
      },
    })
  })

  it('falls back to console.warn when store is unavailable', () => {
    delete window.ss!.store
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

    showToast('No store')

    expect(warnSpy).toHaveBeenCalledWith('[GridEditor] error: No store')
  })
})
