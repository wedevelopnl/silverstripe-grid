import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * gridSettingsField.ts registers a single entwine rule against the
 * override-toggle checkbox inside a .ssgrid-grid-settings-field row. The rule's
 * onchange handler enables/disables the other inputs in that row and toggles
 * a `data-overridden` attribute. These tests stub window.jQuery so importing the
 * module registers the entwine rule against a stubbed `$`, then exercise the
 * onchange handler directly with a controllable checked state and a spying
 * row mock.
 */

type EntwineRule = {
  onchange(this: unknown): void
}

interface RowMock {
  find: ReturnType<typeof vi.fn>
  not: ReturnType<typeof vi.fn>
  prop: ReturnType<typeof vi.fn>
  attr: ReturnType<typeof vi.fn>
  removeAttr: ReturnType<typeof vi.fn>
}

let captured: EntwineRule | null = null
let rowMock: RowMock | null = null
let isCheckedValue = false

function createRowMock(): RowMock {
  const row: RowMock = {
    find: vi.fn(),
    not: vi.fn(),
    prop: vi.fn(),
    attr: vi.fn(),
    removeAttr: vi.fn(),
  }
  row.find.mockReturnValue(row)
  row.not.mockReturnValue(row)
  row.prop.mockReturnValue(row)
  row.attr.mockReturnValue(row)
  row.removeAttr.mockReturnValue(row)
  return row
}

/**
 * Install a fake `window.jQuery` whose `entwine('ss', cb)` calls the callback
 * with a `$` that (a) returns a chain with `.entwine(rule)` that captures the
 * rule on first-call, and (b) returns a chain with `.closest`/`.is` when
 * called with `this` from inside the onchange handler.
 */
function installJQueryStub(): void {
  const entwineFn = (_namespace: string, callback: (dollar: unknown) => void): void => {
    const $ = (_target: unknown): Record<string, unknown> => {
      return {
        // selector-registration path
        entwine: (rule: EntwineRule) => {
          captured = rule
        },
        // onchange runtime path
        closest: (_selector: string): RowMock => {
          if (rowMock === null) {
            throw new Error('rowMock not initialised')
          }
          return rowMock
        },
        is: (_selector: string): boolean => isCheckedValue,
      }
    }
    callback($)
  }

  // biome-ignore lint/suspicious/noExplicitAny: test stub for window.jQuery surface
  ;(window as any).jQuery = Object.assign(() => ({}), { entwine: entwineFn })
}

function removeJQueryStub(): void {
  // biome-ignore lint/suspicious/noExplicitAny: cleanup of test stub
  delete (window as any).jQuery
}

describe('gridSettingsField entwine guard', () => {
  beforeEach(() => {
    captured = null
    rowMock = null
    vi.resetModules()
  })

  afterEach(() => {
    removeJQueryStub()
  })

  it('does not throw when imported without jQuery on the window', async () => {
    removeJQueryStub()
    await expect(import('./gridSettingsField')).resolves.toBeDefined()
    expect(captured).toBeNull()
  })

  it('registers an entwine rule with an onchange handler when jQuery is present', async () => {
    installJQueryStub()
    await import('./gridSettingsField')

    expect(captured).not.toBeNull()
    expect(typeof captured?.onchange).toBe('function')
  })
})

describe('gridSettingsField onchange handler', () => {
  let rule: EntwineRule

  beforeEach(async () => {
    captured = null
    rowMock = createRowMock()
    vi.resetModules()
    installJQueryStub()
    await import('./gridSettingsField')
    // TS narrows `captured` to `null` across the async import boundary because
    // the outer assignment is not visible to control-flow analysis. Read it
    // through an explicit widening cast so downstream type narrowing works.
    const registered = captured as EntwineRule | null
    if (registered === null) {
      throw new Error('rule was not registered')
    }
    rule = registered
  })

  afterEach(() => {
    removeJQueryStub()
  })

  it('enables the row inputs and sets the data-overridden attribute when the toggle is checked', () => {
    isCheckedValue = true

    rule.onchange.call({})

    expect(rowMock?.find).toHaveBeenCalledWith('select, input')
    expect(rowMock?.not).toHaveBeenCalledWith('.ssgrid-grid-settings-field-override-toggle')
    expect(rowMock?.prop).toHaveBeenCalledWith('disabled', false)
    expect(rowMock?.attr).toHaveBeenCalledWith('data-overridden', '')
    expect(rowMock?.removeAttr).not.toHaveBeenCalled()
  })

  it('disables the row inputs and removes the data-overridden attribute when the toggle is unchecked', () => {
    isCheckedValue = false

    rule.onchange.call({})

    expect(rowMock?.prop).toHaveBeenCalledWith('disabled', true)
    expect(rowMock?.removeAttr).toHaveBeenCalledWith('data-overridden')
    expect(rowMock?.attr).not.toHaveBeenCalled()
  })
})
