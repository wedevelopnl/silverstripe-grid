import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushObservers } from '@/testing/flush'
import { registerCmsPreviewBridge, teardownCmsPreviewBridge } from './cmsPreviewBridge'

vi.mock('@/utils/gridAdapter', async () =>
  (await import('@/testing/mockGridAdapter')).mockGridAdapterModule(),
)

/**
 * Minimal CMS DOM approximation. vendorPreview.ts owns the detailed
 * vendor contract — this file only cares about scope gating and
 * lifecycle.
 */
function createCmsDom(options: { withGridEditor?: boolean } = {}): HTMLElement {
  const { withGridEditor = true } = options
  const wrapper = document.createElement('div')
  wrapper.innerHTML = `
    ${withGridEditor ? '<div data-react-mount="grid-editor"></div>' : ''}
    <div class="cms-preview">
      <div class="preview-device-outer"></div>
      <span id="preview-size-dropdown" class="preview-size-selector">
        <select id="preview-size-dropdown-select"></select>
      </span>
    </div>
  `
  document.body.appendChild(wrapper)
  return wrapper
}

describe('cmsPreviewBridge — lifecycle', () => {
  afterEach(() => {
    teardownCmsPreviewBridge()
    document.body.innerHTML = ''
  })

  it('mounts the selector when both grid editor and vendor DOM are present', async () => {
    const root = createCmsDom()
    registerCmsPreviewBridge()
    await flushObservers()

    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull()
    const vendorWrapper = root.querySelector<HTMLElement>('#preview-size-dropdown')
    expect(vendorWrapper?.style.display).toBe('none')
  })

  it('no-ops when the vendor DOM is absent', async () => {
    document.body.innerHTML = '<div data-react-mount="grid-editor"></div>'
    registerCmsPreviewBridge()
    await flushObservers()

    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull()
  })

  it('no-ops on non-grid CMS pages (vendor DOM present, no grid editor)', async () => {
    createCmsDom({ withGridEditor: false })
    registerCmsPreviewBridge()
    await flushObservers()

    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull()
    const vendor = document.getElementById('preview-size-dropdown')
    expect(vendor?.style.display).not.toBe('none')
  })

  it('teardown removes the selector and re-shows the vendor wrapper', async () => {
    const root = createCmsDom()
    registerCmsPreviewBridge()
    await flushObservers()

    teardownCmsPreviewBridge()

    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull()
    const vendor = root.querySelector<HTMLElement>('#preview-size-dropdown')
    expect(vendor?.style.display).not.toBe('none')
  })

  it('remounts after a CMS content-area swap (save/publish Pjax)', async () => {
    const oldRoot = createCmsDom()
    registerCmsPreviewBridge()
    await flushObservers()
    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull()

    // Simulate Pjax swap: remove the old content area, insert a fresh one.
    oldRoot.remove()
    const newRoot = createCmsDom()
    await flushObservers()
    await flushObservers()

    expect(newRoot.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull()
    const newVendor = newRoot.querySelector<HTMLElement>('#preview-size-dropdown')
    expect(newVendor?.style.display).toBe('none')
  })

  it('mounts when the grid editor arrives after registration (vendor DOM first)', async () => {
    const root = createCmsDom({ withGridEditor: false })
    registerCmsPreviewBridge()
    await flushObservers()
    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull()

    const editor = document.createElement('div')
    editor.setAttribute('data-react-mount', 'grid-editor')
    root.appendChild(editor)
    await flushObservers()

    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull()
  })

  it('mounts when the vendor bar arrives after the grid editor', async () => {
    document.body.innerHTML = '<div data-react-mount="grid-editor"></div>'
    registerCmsPreviewBridge()
    await flushObservers()
    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull()

    const preview = document.createElement('div')
    preview.className = 'cms-preview'
    preview.innerHTML = `
      <span id="preview-size-dropdown" class="preview-size-selector">
        <select id="preview-size-dropdown-select"></select>
      </span>
    `
    document.body.appendChild(preview)
    await flushObservers()

    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull()
  })

  it('does not mount on a non-grid page when unrelated DOM is added', async () => {
    const root = createCmsDom({ withGridEditor: false })
    registerCmsPreviewBridge()
    await flushObservers()

    const noise = document.createElement('div')
    noise.innerHTML = '<p>unrelated CMS activity</p>'
    root.appendChild(noise)
    await flushObservers()

    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull()
  })

  it('tears down and restores the vendor bar when only the editor is removed', async () => {
    const root = createCmsDom()
    registerCmsPreviewBridge()
    await flushObservers()
    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull()

    root.querySelector('[data-react-mount="grid-editor"]')?.remove()
    await flushObservers()

    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull()
    const vendor = root.querySelector<HTMLElement>('#preview-size-dropdown')
    expect(vendor?.style.display).not.toBe('none')
  })

  it('stays mounted when the editor is swapped for a replacement in one batch', async () => {
    const root = createCmsDom()
    registerCmsPreviewBridge()
    await flushObservers()

    // Grid page → grid page swap where the vendor bar survives: the old
    // editor leaves and a replacement arrives in the same mutation batch.
    root.querySelector('[data-react-mount="grid-editor"]')?.remove()
    const replacement = document.createElement('div')
    replacement.setAttribute('data-react-mount', 'grid-editor')
    root.appendChild(replacement)
    await flushObservers()

    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull()
    const vendor = root.querySelector<HTMLElement>('#preview-size-dropdown')
    expect(vendor?.style.display).toBe('none')
  })

  it('installs the viewport stylesheet on mount and removes it on teardown', async () => {
    createCmsDom()
    registerCmsPreviewBridge()
    await flushObservers()

    expect(document.getElementById('grid-preview-viewport-styles')).not.toBeNull()

    teardownCmsPreviewBridge()

    expect(document.getElementById('grid-preview-viewport-styles')).toBeNull()
  })
})
