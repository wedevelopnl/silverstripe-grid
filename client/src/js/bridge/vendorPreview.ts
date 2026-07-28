import { getViewports } from '@/utils/gridAdapter'

/**
 * Wrapper around the vendor (`silverstripe/admin`) CMS preview bar.
 *
 * This is the ONLY file in the module that should know about the
 * vendor's DOM structure, entwine namespace, or jQuery integration. The
 * bridge layer above treats the vendor as an opaque object with four
 * operations: take over the dropdown slot, apply / reset a viewport,
 * and release the slot on teardown.
 *
 * If SilverStripe changes the entwine namespace, the DOM ids, or the
 * carrier-class convention in a future release, this file is the one
 * to update — nothing outside it should need to change.
 */

const VENDOR_SELECT_ID = 'preview-size-dropdown-select'
const VENDOR_WRAPPER_ID = 'preview-size-dropdown'
const VENDOR_ROOT_SELECTOR = '.cms-preview'

/**
 * Selector the bridge layer may use to detect that vendor preview DOM has
 * ARRIVED in a mutation batch (presence signal only — all vendor I/O stays in
 * this file). Matches the dropdown wrapper `openVendorPreview` requires; the
 * select lives inside it, so any added subtree that can satisfy a mount
 * contains a match.
 */
export const VENDOR_PRESENCE_SELECTOR = `#${VENDOR_WRAPPER_ID}`
/**
 * The vendor applies device-frame styling (border, centering, rotate
 * affordance, dimension readout) only when `.cms-preview` carries one
 * of `mobile`, `tablet`, or `desktop`. We pick `tablet` as our carrier:
 * it enables both the frame AND the rotate hint, and its orientation
 * matches the orientation our overrides will write.
 */
const VENDOR_FRAME_CLASS = 'tablet'
const ENTWINE_NAMESPACE = 'ss.preview'

type JQueryLike = ((selector: string) => JQuerySelection) | undefined
interface JQuerySelection {
  length: number
  entwine?: (namespace: string) => EntwinePreviewNamespace
  addClass: (cls: string) => unknown
  removeClass: (cls: string) => unknown
}
interface EntwinePreviewNamespace {
  changeSize?: (size: string) => unknown
}

function getJQuery(): JQueryLike {
  return (window as unknown as { jQuery?: JQueryLike }).jQuery
}

function getEntwine(): EntwinePreviewNamespace | null {
  const jq = getJQuery()
  if (typeof jq !== 'function') return null
  const selection = jq(VENDOR_ROOT_SELECTOR)
  if (selection.length === 0 || typeof selection.entwine !== 'function') return null
  try {
    const ns = selection.entwine(ENTWINE_NAMESPACE)
    if (typeof ns.changeSize !== 'function') return null
    return ns
  } catch (error: unknown) {
    // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — surfaces a failure to resolve the ss.preview entwine namespace.
    console.warn('[GridEditor] Could not resolve ss.preview entwine namespace.', error)
    return null
  }
}

/** Compose the `grid-<key>` class list for every viewport the adapter knows. */
function gridClasses(): string {
  return getViewports()
    .map((vp) => `grid-${vp.key}`)
    .join(' ')
}

export interface VendorPreview {
  /**
   * Hide the vendor dropdown wrapper and insert a sibling host element
   * into its parent. The returned host is where the bridge mounts its
   * React root.
   */
  takeOver(hostClassName: string): HTMLElement

  /** Undo `takeOver`: remove the host and re-show the vendor wrapper. */
  release(): void

  /**
   * Apply a viewport key. Uses the vendor `changeSize` path so the
   * device-frame styling, localStorage persistence, and iframe redraw
   * all fire through the vendor's pipeline. Then layers our own
   * `grid-<key>` class on top for width + height overrides.
   *
   * No-op if vendor entwine isn't ready yet — caller should await
   * `whenReady()` before the initial apply.
   */
  applyViewport(key: string): void

  /**
   * Restore the vendor preview to its default `auto` state and strip
   * any lingering `grid-<key>` classes. Used on teardown so unrelated
   * admin pages don't inherit our carrier class.
   */
  resetToAuto(): void

  /**
   * Resolve once vendor entwine has attached to `.cms-preview`. Best-
   * effort single-frame retry — if entwine still isn't ready after the
   * next animation frame, the promise resolves anyway and the subsequent
   * `applyViewport` call will no-op. The idea is to give vendor boot
   * one chance to catch up without building a retry loop.
   */
  whenReady(): Promise<void>
}

/**
 * Try to open a handle to the vendor preview bar. Returns null if the
 * expected vendor DOM (select + wrapper) isn't present.
 */
export function openVendorPreview(): VendorPreview | null {
  const select = document.getElementById(VENDOR_SELECT_ID)
  const wrapper = document.getElementById(VENDOR_WRAPPER_ID)
  if (select === null || wrapper === null || !(wrapper instanceof HTMLElement)) {
    return null
  }

  let host: HTMLElement | null = null

  const takeOver = (hostClassName: string): HTMLElement => {
    wrapper.style.display = 'none'
    // Use a div (not span) because the React component's root is a div
    // and span > div is invalid HTML that some browsers silently fix by
    // closing the span early.
    host = document.createElement('div')
    host.className = hostClassName
    wrapper.parentElement?.insertBefore(host, wrapper)
    return host
  }

  const release = (): void => {
    if (host !== null) {
      host.remove()
      host = null
    }
    wrapper.style.display = ''
  }

  const applyViewport = (key: string): void => {
    const ns = getEntwine()
    if (ns === null) return
    try {
      // 1. Vendor changeSize applies the carrier class (frame styling),
      //    persists the choice, and redraws the iframe.
      ns.changeSize?.(VENDOR_FRAME_CLASS)
      // 2. Vendor only strips its own four known class names, so any
      //    previously-applied grid-<key> stays until we strip it.
      const jq = getJQuery()
      if (typeof jq !== 'function') return
      const selection = jq(VENDOR_ROOT_SELECTOR)
      const classes = gridClasses()
      if (classes !== '') selection.removeClass(classes)
      selection.addClass(`grid-${key}`)
    } catch (error: unknown) {
      // biome-ignore lint/suspicious/noConsole: intentional operator diagnostic — logs a vendor applyViewport failure in the preview bridge.
      console.warn('[GridEditor] Vendor applyViewport failed.', error)
    }
  }

  const resetToAuto = (): void => {
    const ns = getEntwine()
    const jq = getJQuery()
    try {
      ns?.changeSize?.('auto')
      if (typeof jq === 'function') {
        const classes = gridClasses()
        if (classes !== '') jq(VENDOR_ROOT_SELECTOR).removeClass(classes)
      }
    } catch {
      // best-effort cleanup
    }
  }

  const whenReady = (): Promise<void> =>
    new Promise<void>((resolve) => {
      if (getEntwine() !== null) {
        resolve()
        return
      }
      // Single retry on the next frame. If entwine still isn't ready
      // by then, resolve anyway and let the first applyViewport no-op.
      // Users hitting this path will see the initial preview at the
      // vendor default; a subsequent viewport click recovers.
      if (typeof requestAnimationFrame === 'function') {
        requestAnimationFrame(() => resolve())
      } else {
        resolve()
      }
    })

  return { takeOver, release, applyViewport, resetToAuto, whenReady }
}
