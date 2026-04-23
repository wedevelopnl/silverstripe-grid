import { afterEach, describe, expect, it, vi } from 'vitest';
import { registerCmsPreviewBridge, teardownCmsPreviewBridge } from './cmsPreviewBridge';

vi.mock('@/utils/gridAdapter', () => ({
  getDefaultViewport: () => 'md',
  getViewports: () => [
    { key: 'xs', label: 'Extra Small', minWidth: 0 },
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
  ],
}));

/**
 * Minimal CMS DOM approximation. vendorPreview.ts owns the detailed
 * vendor contract — this file only cares about scope gating and
 * lifecycle.
 */
function createCmsDom(options: { withGridEditor?: boolean } = {}): HTMLElement {
  const { withGridEditor = true } = options;
  const wrapper = document.createElement('div');
  wrapper.innerHTML = `
    ${withGridEditor ? '<div class="grid-editor__container"></div>' : ''}
    <div class="cms-preview">
      <div class="preview-device-outer"></div>
      <span id="preview-size-dropdown" class="preview-size-selector">
        <select id="preview-size-dropdown-select"></select>
      </span>
    </div>
  `;
  document.body.appendChild(wrapper);
  return wrapper;
}

describe('cmsPreviewBridge — lifecycle', () => {
  afterEach(() => {
    teardownCmsPreviewBridge();
    document.body.innerHTML = '';
  });

  it('mounts the selector when both grid editor and vendor DOM are present', async () => {
    const root = createCmsDom();
    registerCmsPreviewBridge();
    await new Promise((r) => setTimeout(r, 0));

    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull();
    const vendorWrapper = root.querySelector<HTMLElement>('#preview-size-dropdown');
    expect(vendorWrapper?.style.display).toBe('none');
  });

  it('no-ops when the vendor DOM is absent', async () => {
    document.body.innerHTML = '<div class="grid-editor__container"></div>';
    registerCmsPreviewBridge();
    await new Promise((r) => setTimeout(r, 0));

    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull();
  });

  it('no-ops on non-grid CMS pages (vendor DOM present, no grid editor)', async () => {
    createCmsDom({ withGridEditor: false });
    registerCmsPreviewBridge();
    await new Promise((r) => setTimeout(r, 0));

    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull();
    const vendor = document.getElementById('preview-size-dropdown');
    expect(vendor?.style.display).not.toBe('none');
  });

  it('teardown removes the selector and re-shows the vendor wrapper', async () => {
    const root = createCmsDom();
    registerCmsPreviewBridge();
    await new Promise((r) => setTimeout(r, 0));

    teardownCmsPreviewBridge();

    expect(root.querySelector('[data-testid="cms-preview-viewport-selector"]')).toBeNull();
    const vendor = root.querySelector<HTMLElement>('#preview-size-dropdown');
    expect(vendor?.style.display).not.toBe('none');
  });

  it('remounts after a CMS content-area swap (save/publish Pjax)', async () => {
    const oldRoot = createCmsDom();
    registerCmsPreviewBridge();
    await new Promise((r) => setTimeout(r, 0));
    expect(document.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull();

    // Simulate Pjax swap: remove the old content area, insert a fresh one.
    oldRoot.remove();
    const newRoot = createCmsDom();
    await new Promise((r) => setTimeout(r, 0));
    await new Promise((r) => setTimeout(r, 0));

    expect(newRoot.querySelector('[data-testid="cms-preview-viewport-selector"]')).not.toBeNull();
    const newVendor = newRoot.querySelector<HTMLElement>('#preview-size-dropdown');
    expect(newVendor?.style.display).toBe('none');
  });

  it('installs the viewport stylesheet on mount and removes it on teardown', async () => {
    createCmsDom();
    registerCmsPreviewBridge();
    await new Promise((r) => setTimeout(r, 0));

    expect(document.getElementById('grid-preview-viewport-styles')).not.toBeNull();

    teardownCmsPreviewBridge();

    expect(document.getElementById('grid-preview-viewport-styles')).toBeNull();
  });
});
