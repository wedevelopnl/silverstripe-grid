import { createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import CmsPreviewViewportSelector from '@/components/CmsPreviewViewportSelector/CmsPreviewViewportSelector';

const VENDOR_SELECT_ID = 'preview-size-dropdown-select';
const VENDOR_WRAPPER_ID = 'preview-size-dropdown';
const MOUNT_CLASS = 'cms-preview-viewport-mount';

let observer: MutationObserver | null = null;
let mountedRoot: Root | null = null;
let mountedHost: HTMLElement | null = null;
let hiddenVendorWrapper: HTMLElement | null = null;

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
  }
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
}
