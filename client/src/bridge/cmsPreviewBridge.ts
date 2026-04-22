import { createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import CmsPreviewViewportSelector from '@/components/CmsPreviewViewportSelector/CmsPreviewViewportSelector';
import {
  MOBILE_FIRST_PREVIEW_WIDTH,
  getActiveViewport,
  subscribeActiveViewport,
} from '@/state/activeViewport';
import { getViewports } from '@/utils/gridAdapter';

const VENDOR_SELECT_ID = 'preview-size-dropdown-select';
const VENDOR_WRAPPER_ID = 'preview-size-dropdown';
// Presence of the grid editor is the gate for mounting the preview
// selector. Without this, our bundle would hijack the CMS preview bar
// on every previewable admin page (Files, Blog, SiteTree non-grid
// pages) — all of which have the vendor dropdown but no grid.
const GRID_EDITOR_SELECTOR = '.grid-editor__container';
const MOUNT_CLASS = 'cms-preview-viewport-mount';
const STYLE_TAG_ID = 'grid-preview-viewport-styles';

let observer: MutationObserver | null = null;
let mountedRoot: Root | null = null;
let mountedHost: HTMLElement | null = null;
let hiddenVendorWrapper: HTMLElement | null = null;
let unsubscribe: (() => void) | null = null;

/**
 * Return a sensible device-frame height for a given preview width.
 *
 * Monotonic: a wider viewport always gets a taller (or equal) frame so
 * no "smaller" breakpoint ever appears visually bigger than a "larger"
 * one. A 500px floor keeps narrow mobile views usable; a 900px cap
 * keeps wide desktop previews from overflowing a typical split-mode
 * panel. The 0.75 factor gives a roughly 4:3 landscape for widths in
 * between.
 */
function heightForWidth(width: number): number {
  const floor = 500;
  const cap = 900;
  return Math.min(cap, Math.max(floor, Math.round(width * 0.75)));
}

function injectViewportStyles(): void {
  // Vendor's `&.tablet` rules set width AND height on .preview-device-outer,
  // plus a fixed `content` string on .preview__device::after (the
  // dimension label below the iframe) pinned to vendor's tablet preset
  // (768×1024). We override all three so the frame reflects our
  // adapter's actual breakpoint widths plus a matching reasonable height.
  //
  // min-width and min-height are also set by the vendor (line 402 + 410
  // of _preview.scss) — we override those too so the outer wrapper
  // collapses to our actual dimensions instead of forcing 768px minimum.
  const rules = getViewports()
    .map((vp) => {
      const width = vp.minWidth > 0 ? vp.minWidth : MOBILE_FIRST_PREVIEW_WIDTH;
      const height = heightForWidth(width);
      const selectorBase = `.cms-preview.grid-${vp.key}`;
      return [
        `${selectorBase} .preview-device-outer {`,
        `  width: ${width}px;`,
        `  height: ${height}px;`,
        `  min-height: 0;`,
        `}`,
        `${selectorBase} .preview__device {`,
        `  min-width: calc(${width}px + 4 * 8px);`,
        `}`,
        `${selectorBase} .preview__device::after {`,
        `  content: '${vp.label} · ${width}px × ${height}px';`,
        `}`,
      ].join('\n');
    })
    .join('\n');

  let style = document.getElementById(STYLE_TAG_ID) as HTMLStyleElement | null;
  if (style === null) {
    style = document.createElement('style');
    style.id = STYLE_TAG_ID;
    document.head.appendChild(style);
  }
  style.textContent = rules;
}

/**
 * Vendor "carrier" class that triggers the device-frame styling on
 * `.cms-preview` (border, centering, dimension readout, Rotate hint).
 * The vendor applies this block to `&.mobile, &.tablet, &.desktop` — we
 * pick `tablet` because it's the only preset that enables both the frame
 * AND the Rotate affordance while matching a grid-friendly orientation.
 * Our own `grid-<key>` class (added alongside) overrides the actual width.
 */
const VENDOR_FRAME_CLASS = 'tablet';

// Number of retries left for the initial sync if entwine isn't ready
// yet. Reset by attemptMount before each mount cycle.
const ENTWINE_READY_RETRIES = 20;
const ENTWINE_READY_RETRY_MS = 50;
let pendingSyncRetry: ReturnType<typeof setTimeout> | null = null;

function callVendorChangeSize(key: string, retriesLeft: number = ENTWINE_READY_RETRIES): void {
  // biome-ignore lint/suspicious/noExplicitAny: jQuery global is untyped by design
  const jq = (window as any).jQuery;
  const entwineReady = (): {
    changeSize: (size: string) => unknown;
    removeClass: (cls: string) => unknown;
    addClass: (cls: string) => unknown;
  } | null => {
    if (typeof jq !== 'function') return null;
    const selection = jq('.cms-preview');
    if (
      selection === undefined ||
      selection.length === 0 ||
      typeof selection.entwine !== 'function'
    )
      return null;
    let ns: { changeSize?: (size: string) => unknown };
    try {
      ns = selection.entwine('ss.preview');
    } catch (error: unknown) {
      console.warn('[GridEditor] Could not resolve ss.preview entwine namespace.', error);
      return null;
    }
    if (typeof ns.changeSize !== 'function') return null;
    return {
      changeSize: ns.changeSize.bind(ns),
      removeClass: selection.removeClass.bind(selection),
      addClass: selection.addClass.bind(selection),
    };
  };

  const ready = entwineReady();
  if (ready === null) {
    // Vendor entwine may attach asynchronously after DOMContentLoaded.
    // Retry briefly so the initial sync lands once vendor is ready,
    // then give up rather than hammer the event loop.
    if (retriesLeft > 0) {
      if (pendingSyncRetry !== null) {
        clearTimeout(pendingSyncRetry);
      }
      pendingSyncRetry = setTimeout(() => {
        pendingSyncRetry = null;
        callVendorChangeSize(key, retriesLeft - 1);
      }, ENTWINE_READY_RETRY_MS);
    }
    return;
  }

  try {
    // 1. Let vendor changeSize set the carrier class (VENDOR_FRAME_CLASS).
    //    This triggers the device-frame styling + localStorage persistence
    //    + iframe redraw via the vendor's own pipeline.
    ready.changeSize(VENDOR_FRAME_CLASS);
    // 2. Swap any previously-applied grid-<key> class for the new one.
    //    Must happen AFTER changeSize — vendor only strips its own 4
    //    known class names ('auto desktop tablet mobile'), leaving grid-*
    //    classes in place. We strip ours and add the current one.
    const gridClasses = getViewports()
      .map((vp) => `grid-${vp.key}`)
      .join(' ');
    if (gridClasses !== '') {
      ready.removeClass(gridClasses);
    }
    ready.addClass(`grid-${key}`);
  } catch (error: unknown) {
    console.warn('[GridEditor] Vendor changeSize failed.', error);
  }
}

function syncPreview(): void {
  const key = getActiveViewport();
  if (key === '') {
    return;
  }
  // Vendor changeSize() toggles the `.cms-preview.grid-<key>` class which
  // our injected stylesheet (injectViewportStyles) maps to a pixel width.
  callVendorChangeSize(key);
}

function attemptMount(): void {
  if (mountedRoot !== null) {
    return;
  }

  // Gate on grid-editor presence. Without this, our bundle would hijack
  // the CMS preview bar on every previewable admin page.
  const editorPresent = document.querySelector(GRID_EDITOR_SELECTOR) !== null;
  if (!editorPresent) {
    return;
  }

  const select = document.getElementById(VENDOR_SELECT_ID);
  const wrapper = document.getElementById(VENDOR_WRAPPER_ID);

  if (select === null || wrapper === null || !(wrapper instanceof HTMLElement)) {
    return;
  }

  // Hide the vendor wrapper, mount our own selector as its sibling.
  wrapper.style.display = 'none';
  hiddenVendorWrapper = wrapper;

  // Use a div (not span) because CmsPreviewViewportSelector's root is a
  // div; nesting a div inside a span would be invalid HTML and browsers
  // can close the span early in rare layouts.
  //
  // Classes: `preview-selector` piggy-backs on vendor CSS so the mount
  // floats right and aligns with the vendor mode selector + preview
  // states pills. MOUNT_CLASS is our own anchor for teardown and tests.
  const host = document.createElement('div');
  host.className = `${MOUNT_CLASS} preview-selector`;
  wrapper.parentElement?.insertBefore(host, wrapper);
  mountedHost = host;

  try {
    mountedRoot = createRoot(host);
    mountedRoot.render(createElement(CmsPreviewViewportSelector));
  } catch (error: unknown) {
    console.warn('[GridEditor] Failed to mount CMS preview viewport selector.', error);
    teardownCmsPreviewBridge();
    return;
  }

  // Provide CSS rules for each `grid-<key>` class the vendor may toggle.
  // Must happen before the first syncPreview so the initial render lands
  // at the correct width.
  injectViewportStyles();

  // Subscribe to store changes to drive the preview iframe.
  unsubscribe = subscribeActiveViewport(syncPreview);
  syncPreview(); // initial application
}

/**
 * Release the React root and related state so the next mutation can
 * mount a fresh selector over the NEW vendor DOM. Used when the CMS
 * Pjax-swaps the content area (e.g. after save/publish) which detaches
 * our previously-hidden vendor wrapper.
 *
 * Unlike `teardownCmsPreviewBridge`, this keeps the MutationObserver
 * running. It also doesn't touch the vendor preview mode — at this
 * point the vendor wrapper is already gone, so there's no class to
 * clean up and no `changeSize` to call.
 */
function detachForRemount(): void {
  if (pendingSyncRetry !== null) {
    clearTimeout(pendingSyncRetry);
    pendingSyncRetry = null;
  }
  if (unsubscribe !== null) {
    unsubscribe();
    unsubscribe = null;
  }
  if (mountedRoot !== null) {
    try {
      mountedRoot.unmount();
    } catch {
      // ignore — node may already be detached
    }
    mountedRoot = null;
  }
  if (mountedHost !== null) {
    try {
      mountedHost.remove();
    } catch {
      // ignore
    }
    mountedHost = null;
  }
  hiddenVendorWrapper = null;
  const style = document.getElementById(STYLE_TAG_ID);
  if (style !== null) {
    style.remove();
  }
}

function attemptUnmount(): void {
  if (mountedRoot === null) {
    return;
  }
  // Two conditions drop the mount (but keep the observer alive so a
  // later mutation can remount):
  //
  // 1. The previously-hidden vendor wrapper was detached — e.g. Pjax
  //    swap of the content area after save/publish.
  // 2. The grid editor is no longer on the page — e.g. user navigated
  //    to a non-grid admin section while the bundle is still alive. In
  //    this case we also restore the vendor preview bar so unrelated
  //    pages don't see the lingering `tablet` carrier class or our
  //    injected per-viewport stylesheet.
  const vendorGone = hiddenVendorWrapper !== null && !hiddenVendorWrapper.isConnected;
  const editorGone = document.querySelector(GRID_EDITOR_SELECTOR) === null;

  if (vendorGone) {
    detachForRemount();
    return;
  }
  if (editorGone) {
    // Fully tear down: restore vendor state so we don't leave stale
    // classes on a non-grid page's preview bar.
    teardownActiveMount();
  }
}

/**
 * Undo everything `attemptMount` did, but keep the MutationObserver
 * running so we can remount if the grid editor reappears in this
 * session (e.g. user navigates back).
 */
function teardownActiveMount(): void {
  // Vendor wrapper is still connected here — reach through the store
  // to restore auto preview mode and strip grid-<key> classes so the
  // vendor bar returns to its default state.
  restoreVendorPreview();
  detachForRemount();
  // detachForRemount cleared hiddenVendorWrapper, so re-show the
  // wrapper BEFORE that — moved into the restore function below.
}

/**
 * Best-effort restoration of the vendor preview bar to its default
 * `auto` state. Called when the grid editor leaves the DOM so a
 * lingering `tablet` class + our stylesheet don't misrepresent preview
 * sizing on unrelated admin pages.
 */
function restoreVendorPreview(): void {
  // Re-show the vendor wrapper first so the native UI is available.
  if (hiddenVendorWrapper !== null) {
    hiddenVendorWrapper.style.display = '';
  }
  // biome-ignore lint/suspicious/noExplicitAny: jQuery is untyped
  const jq = (window as any).jQuery;
  if (typeof jq !== 'function') {
    return;
  }
  try {
    const selection = jq('.cms-preview');
    if (selection.length === 0) {
      return;
    }
    if (typeof selection.entwine === 'function') {
      const ns = selection.entwine('ss.preview');
      if (typeof ns.changeSize === 'function') {
        ns.changeSize('auto');
      }
    }
    const gridClasses = getViewports()
      .map((vp) => `grid-${vp.key}`)
      .join(' ');
    if (gridClasses !== '') {
      selection.removeClass(gridClasses);
    }
  } catch {
    // best-effort — ignore failures
  }
}

export function registerCmsPreviewBridge(): void {
  if (observer !== null) {
    return;
  }
  if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
    return;
  }

  // Order matters: detach state if the old wrapper is gone BEFORE
  // attempting to mount against the new DOM. Running mount first would
  // short-circuit on the stale `mountedRoot` reference.
  observer = new MutationObserver(() => {
    attemptUnmount();
    attemptMount();
  });

  observer.observe(document.body, { childList: true, subtree: true });

  // Initial pass in case the vendor DOM is already present.
  attemptMount();
}

export function teardownCmsPreviewBridge(): void {
  // Unsubscribe first so no further callbacks fire during teardown.
  if (unsubscribe !== null) {
    unsubscribe();
    unsubscribe = null;
  }

  if (observer !== null) {
    observer.disconnect();
    observer = null;
  }

  if (mountedRoot !== null) {
    try {
      mountedRoot.unmount();
    } catch (error: unknown) {
      console.warn('[GridEditor] Error during CMS preview selector unmount.', error);
    }
    mountedRoot = null;
  }

  if (mountedHost !== null) {
    mountedHost.remove();
    mountedHost = null;
  }

  if (hiddenVendorWrapper !== null) {
    hiddenVendorWrapper.style.display = '';
    hiddenVendorWrapper = null;
  }

  // Restore the vendor preview to its default "auto" state and strip our
  // grid-<key> classes. Ordering: changeSize('auto') first (vendor strips
  // its carrier class + applies auto styling), then remove grid-* (vendor
  // changeSize doesn't know about our classes).
  // biome-ignore lint/suspicious/noExplicitAny: jQuery is untyped
  const jq = (window as any).jQuery;
  if (typeof jq === 'function') {
    try {
      const selection = jq('.cms-preview');
      if (selection.length > 0 && typeof selection.entwine === 'function') {
        const ns = selection.entwine('ss.preview');
        if (typeof ns.changeSize === 'function') {
          ns.changeSize('auto');
        }
      }
      const gridClasses = getViewports()
        .map((vp) => `grid-${vp.key}`)
        .join(' ');
      if (gridClasses !== '') {
        selection.removeClass(gridClasses);
      }
    } catch {
      // ignore — best-effort cleanup
    }
  }

  const style = document.getElementById(STYLE_TAG_ID);
  if (style !== null) {
    style.remove();
  }
}
