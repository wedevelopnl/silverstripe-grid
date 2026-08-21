import { createElement } from 'react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

// The real dialog fetches usage on mount; the bridge's job is only to bind the
// CMS button to it, so a stub keeps this test about the wiring.
const dialogProps = vi.fn()

vi.mock('@/components/SharedBlockDeleteDialog/SharedBlockDeleteDialog', () => ({
  default: (props: Record<string, unknown>) => {
    dialogProps(props)
    return createElement('div', { 'data-testid': 'dialog-stub' })
  },
}))

// Registered once for the whole suite, deliberately: the listener is delegated
// from the document and the bridge offers no way to detach it, so re-importing
// per test would leave every previous registration firing too.
import { flushObservers } from '@/testing/flush'
import { closeSharedBlockDeleteDialog, registerSharedBlockDeleteBridge } from './sharedBlockDelete'

function createButton(attributes: Record<string, string>): HTMLButtonElement {
  const button = document.createElement('button')
  for (const [name, value] of Object.entries(attributes)) {
    button.setAttribute(name, value)
  }
  document.body.appendChild(button)
  return button
}

describe('shared block delete bridge', () => {
  beforeAll(() => {
    registerSharedBlockDeleteBridge()
  })

  beforeEach(() => {
    closeSharedBlockDeleteDialog()
    document.body.innerHTML = ''
    dialogProps.mockClear()
  })

  it('opens the dialog for the block the button names', async () => {
    createButton({
      'data-grid-shared-block-delete': '7',
      'data-grid-shared-block-title': 'Promo banner',
      'data-grid-shared-block-return': '/admin/shared-blocks/',
    }).click()
    await flushObservers()

    expect(dialogProps).toHaveBeenCalledWith(
      expect.objectContaining({ isOpen: true, blockId: 7, blockTitle: 'Promo banner' }),
    )
  })

  it('cancels the button default so the surrounding CMS form is not submitted', async () => {
    const button = createButton({ 'data-grid-shared-block-delete': '7' })
    const event = new MouseEvent('click', { bubbles: true, cancelable: true })
    button.dispatchEvent(event)
    await flushObservers()

    expect(event.defaultPrevented).toBe(true)
  })

  it('opens from a click on content inside the button', async () => {
    const button = createButton({ 'data-grid-shared-block-delete': '7' })
    const icon = document.createElement('span')
    button.appendChild(icon)

    icon.click()
    await flushObservers()

    // Not toHaveBeenCalledOnce: the mount runs under StrictMode, which
    // double-invokes components against Vitest's development React build.
    expect(dialogProps).toHaveBeenCalledWith(expect.objectContaining({ blockId: 7 }))
  })

  it.each([
    ['a missing block id', {}],
    ['a non-numeric block id', { 'data-grid-shared-block-delete': 'abc' }],
    ['a zero block id', { 'data-grid-shared-block-delete': '0' }],
  ])('ignores a trigger with %s', async (_label, attributes) => {
    createButton(attributes).click()
    await flushObservers()

    expect(dialogProps).not.toHaveBeenCalled()
  })

  it('ignores clicks outside a trigger', async () => {
    const other = document.createElement('button')
    document.body.appendChild(other)

    other.click()
    await flushObservers()

    expect(dialogProps).not.toHaveBeenCalled()
  })

  it('tears the dialog host back out of the document when closed', async () => {
    createButton({ 'data-grid-shared-block-delete': '7' }).click()
    await flushObservers()

    expect(document.getElementById('ssgrid-shared-block-delete-host')).not.toBeNull()

    closeSharedBlockDeleteDialog()
    await flushObservers()

    expect(document.getElementById('ssgrid-shared-block-delete-host')).toBeNull()
  })
})
