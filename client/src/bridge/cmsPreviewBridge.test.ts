import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { registerCmsPreviewBridge, teardownCmsPreviewBridge } from './cmsPreviewBridge';

vi.mock('@/utils/gridAdapter', () => ({
  getDefaultViewport: () => 'md',
  getViewports: () => [
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
  ],
}));

function createVendorPreviewDom(): HTMLElement {
  const wrapper = document.createElement('div');
  wrapper.innerHTML = `
    <div class="cms-preview">
      <div class="preview-device-outer"></div>
      <span id="preview-size-dropdown" class="preview-size-selector">
        <select id="preview-size-dropdown-select">
          <option value="auto">Auto</option>
          <option value="desktop">Desktop</option>
        </select>
      </span>
    </div>
  `;
  document.body.appendChild(wrapper);
  return wrapper;
}

describe('cmsPreviewBridge', () => {
  afterEach(() => {
    teardownCmsPreviewBridge();
    document.body.innerHTML = '';
  });

  it('mounts the custom selector when the vendor DOM exists', async () => {
    const root = createVendorPreviewDom();
    registerCmsPreviewBridge();

    // MutationObserver is async — flush microtasks
    await Promise.resolve();

    expect(root.querySelector('.cms-preview-viewport-selector')).toBeDefined();
    const vendor = root.querySelector<HTMLElement>('#preview-size-dropdown');
    expect(vendor?.style.display).toBe('none');
  });

  it('no-ops when the vendor DOM is absent', async () => {
    registerCmsPreviewBridge();
    await Promise.resolve();

    expect(document.querySelector('.cms-preview-viewport-selector')).toBeNull();
  });

  it('tears down on unregister — removes mount and restores vendor select', async () => {
    const root = createVendorPreviewDom();
    registerCmsPreviewBridge();
    await Promise.resolve();

    teardownCmsPreviewBridge();

    expect(root.querySelector('.cms-preview-viewport-selector')).toBeNull();
    const vendor = root.querySelector<HTMLElement>('#preview-size-dropdown');
    expect(vendor?.style.display).not.toBe('none');
  });
});
