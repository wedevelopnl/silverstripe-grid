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
const MOUNT_CLASS = 'cms-preview-viewport-mount';
const STYLE_TAG_ID = 'grid-preview-viewport-styles';

let observer: MutationObserver | null = null;
let mountedRoot: Root | null = null;
let mountedHost: HTMLElement | null = null;
let hiddenVendorWrapper: HTMLElement | null = null;
let unsubscribe: (() => void) | null = null;

/**
 * Inject per-viewport CSS rules that match the classes the vendor
 * `changeSize()` method adds to `.cms-preview`. Format and specificity
 * mirror the vendor's own viewport rules (`.cms-preview.desktop
 * .preview-device-outer`, etc.), so this layer composes with core
 * rather than fighting it.
 *
 * Runs once on mount — the ruleset is static (derived from adapter
 * config). Vendor `changeSize(grid-<key>)` toggles which rule matches.
 */
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

function callVendorChangeSize(key: string): void {
  // biome-ignore lint/suspicious/noExplicitAny: jQuery global is untyped by design
  const jq = (window as any).jQuery;
  if (typeof jq !== 'function') {
    return;
  }
  const selection = jq('.cms-preview');
  if (
    selection === undefined ||
    selection.length === 0 ||
    typeof selection.entwine !== 'function'
  ) {
    return;
  }
  // Vendor entwine rules for .cms-preview live under namespace `ss.preview`.
  // `changeSize` isn't exposed on plain jQuery — only via `.entwine('ss.preview')`.
  let ns: { changeSize?: (size: string) => unknown };
  try {
    ns = selection.entwine('ss.preview');
  } catch (error: unknown) {
    console.warn('[GridEditor] Could not resolve ss.preview entwine namespace.', error);
    return;
  }
  if (typeof ns.changeSize !== 'function') {
    return;
  }
  try {
    // 1. Let vendor changeSize set the carrier class (VENDOR_FRAME_CLASS).
    //    This triggers the device-frame styling + localStorage persistence
    //    + iframe redraw via the vendor's own pipeline.
    ns.changeSize(VENDOR_FRAME_CLASS);
    // 2. Swap any previously-applied grid-<key> class for the new one.
    //    Must happen AFTER changeSize — vendor only strips its own 4
    //    known class names ('auto desktop tablet mobile'), leaving grid-*
    //    classes in place. We strip ours and add the current one.
    const gridClasses = getViewports()
      .map((vp) => `grid-${vp.key}`)
      .join(' ');
    if (gridClasses !== '') {
      selection.removeClass(gridClasses);
    }
    selection.addClass(`grid-${key}`);
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
  const host = document.createElement('div');
  host.className = MOUNT_CLASS;
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

function attemptUnmount(): void {
  // If the vendor wrapper has been removed from the DOM by a CMS navigation,
  // detach our mount too.
  if (hiddenVendorWrapper !== null && !hiddenVendorWrapper.isConnected) {
    teardownCmsPreviewBridge();
  }
}

export function registerCmsPreviewBridge(): void {
  if (observer !== null) {
    return;
  }
  if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
    return;
  }

  observer = new MutationObserver(() => {
    attemptMount();
    attemptUnmount();
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
