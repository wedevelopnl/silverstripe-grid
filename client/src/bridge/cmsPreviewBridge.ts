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
const STYLE_TAG_ID = 'grid-preview-viewport-override';

let observer: MutationObserver | null = null;
let mountedRoot: Root | null = null;
let mountedHost: HTMLElement | null = null;
let hiddenVendorWrapper: HTMLElement | null = null;
let unsubscribe: (() => void) | null = null;

function widthForKey(key: string): number {
  const vp = getViewports().find((v) => v.key === key);
  if (vp === undefined) {
    return MOBILE_FIRST_PREVIEW_WIDTH;
  }
  return vp.minWidth > 0 ? vp.minWidth : MOBILE_FIRST_PREVIEW_WIDTH;
}

function applyInlineWidth(pixels: number): void {
  let style = document.getElementById(STYLE_TAG_ID) as HTMLStyleElement | null;
  if (style === null) {
    style = document.createElement('style');
    style.id = STYLE_TAG_ID;
    document.head.appendChild(style);
  }
  style.textContent = `.cms-preview .preview-device-outer { width: ${pixels}px; height: 100%; }`;
}

function callVendorChangeSize(key: string): void {
  // biome-ignore lint/suspicious/noExplicitAny: jQuery global is untyped by design
  const jq = (window as any).jQuery;
  if (typeof jq !== 'function') {
    return;
  }
  const selection = jq('.cms-preview');
  if (
    selection === undefined ||
    typeof selection.changeSize !== 'function' ||
    selection.length === 0
  ) {
    return;
  }
  try {
    selection.changeSize(`grid-${key}`);
  } catch (error: unknown) {
    console.warn('[GridEditor] Vendor changeSize failed, relying on inline width fallback.', error);
  }
}

function syncPreview(): void {
  const key = getActiveViewport();
  if (key === '') {
    return;
  }
  applyInlineWidth(widthForKey(key));
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

  const style = document.getElementById(STYLE_TAG_ID);
  if (style !== null) {
    style.remove();
  }
}
