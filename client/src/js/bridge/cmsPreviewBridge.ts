import { createElement, StrictMode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import CmsPreviewViewportSelector from '@/components/CmsPreviewViewportSelector/CmsPreviewViewportSelector'
import { getActiveViewport, subscribeActiveViewport } from '@/state/activeViewport'
import { openVendorPreview, type VendorPreview } from './vendorPreview'
import { installViewportStyles, removeViewportStyles } from './viewportPreviewStyles'

/**
 * Lifecycle coordinator for the CMS preview viewport selector.
 *
 * Responsibilities are narrow by design: detect when the page has both
 * a grid editor AND the vendor preview bar, mount our React selector
 * over the vendor dropdown slot, keep them in sync, and clean up when
 * either leaves the DOM.
 *
 * Vendor I/O (jQuery, entwine, class juggling) lives in `vendorPreview.ts`.
 * Stylesheet generation lives in `viewportPreviewStyles.ts`. Neither
 * should be imported from anywhere else in the module.
 */

const GRID_EDITOR_SELECTOR = '[data-react-mount="grid-editor"]'
const MOUNT_CLASS = 'cms-preview-viewport-mount preview-selector'

let observer: MutationObserver | null = null
let mountedRoot: Root | null = null
let mountedHost: HTMLElement | null = null
let vendor: VendorPreview | null = null
let unsubscribe: (() => void) | null = null

function editorPresent(): boolean {
  return document.querySelector(GRID_EDITOR_SELECTOR) !== null
}

function attemptMount(): void {
  if (mountedRoot !== null) return
  if (!editorPresent()) return

  const handle = openVendorPreview()
  if (handle === null) return

  vendor = handle
  mountedHost = handle.takeOver(MOUNT_CLASS)

  try {
    mountedRoot = createRoot(mountedHost)
    // StrictMode documents the intent to run under React's strict checks, but
    // is inert in the CMS: `react-dom` is externalized to silverstripe/admin's
    // *production* React global, whose reconciler has no double-invoke logic.
    // It only activates if a development React build is ever provided (e.g. the
    // Vitest suite, which uses react-dom.development).
    mountedRoot.render(createElement(StrictMode, null, createElement(CmsPreviewViewportSelector)))
  } catch (error: unknown) {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — surfaces a preview-selector mount failure in the CMS bridge.
    console.warn('[GridEditor] Failed to mount CMS preview viewport selector.', error)
    teardownMount({ restoreVendor: false })
    return
  }

  installViewportStyles()
  const applyActiveViewport = (): void => {
    const key = getActiveViewport()
    if (key !== null) {
      vendor?.applyViewport(key)
    }
  }
  unsubscribe = subscribeActiveViewport(applyActiveViewport)

  // Apply the current viewport once entwine is ready. If it isn't by
  // the next frame, the first apply no-ops and the preview stays at
  // vendor default until the user interacts.
  vendor.whenReady().then(applyActiveViewport)
}

/**
 * Unwind everything `attemptMount` did. When `restoreVendor` is true
 * we also return the vendor preview bar to its default `auto` state —
 * used when the grid editor leaves the DOM, so non-grid pages don't
 * inherit our carrier class. When false (Pjax swap), the vendor DOM
 * is already gone and there's nothing to restore.
 */
function teardownMount({ restoreVendor }: { restoreVendor: boolean }): void {
  if (unsubscribe !== null) {
    unsubscribe()
    unsubscribe = null
  }

  if (mountedRoot !== null) {
    try {
      mountedRoot.unmount()
    } catch (error: unknown) {
      // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — logs the swallowed preview-selector unmount error.
      console.warn('[GridEditor] Error during CMS preview selector unmount.', error)
    }
    mountedRoot = null
  }

  mountedHost = null

  if (vendor !== null) {
    if (restoreVendor) {
      vendor.resetToAuto()
    }
    vendor.release()
    vendor = null
  }

  removeViewportStyles()
}

function attemptUnmount(): void {
  if (mountedRoot === null) return

  // Pjax-style swap: the host we inserted is no longer connected because
  // the CMS replaced the whole content area. Drop our state so the next
  // mount cycle can attach to the fresh vendor DOM. Don't try to reset
  // vendor — there's nothing connected to reset.
  if (mountedHost !== null && !mountedHost.isConnected) {
    teardownMount({ restoreVendor: false })
    return
  }

  // User navigated away from a grid page while the bundle is still
  // alive. Restore the vendor bar so unrelated admin pages don't see
  // the lingering carrier class or our stylesheet.
  if (!editorPresent()) {
    teardownMount({ restoreVendor: true })
  }
}

export function registerCmsPreviewBridge(): void {
  if (observer !== null) return
  if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') return

  // Order matters: unmount-if-stale BEFORE mount. Running mount first
  // would short-circuit on a stale `mountedRoot` and miss the fresh DOM.
  observer = new MutationObserver(() => {
    attemptUnmount()
    attemptMount()
  })

  observer.observe(document.body, { childList: true, subtree: true })

  attemptMount()
}

export function teardownCmsPreviewBridge(): void {
  if (observer !== null) {
    observer.disconnect()
    observer = null
  }
  if (mountedRoot !== null) {
    teardownMount({ restoreVendor: true })
  }
}
