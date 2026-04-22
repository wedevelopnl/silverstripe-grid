import { afterEach, describe, expect, it, vi } from 'vitest';
import { registerCmsPreviewBridge, teardownCmsPreviewBridge } from './cmsPreviewBridge';
import { setActiveViewport } from '@/state/activeViewport';

vi.mock('@/utils/gridAdapter', () => ({
  getDefaultViewport: () => 'md',
  getViewports: () => [
    { key: 'xs', label: 'Extra Small', minWidth: 0 },
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

describe('cmsPreviewBridge — resize mechanics', () => {
  afterEach(() => {
    teardownCmsPreviewBridge();
    document.body.innerHTML = '';
    // biome-ignore lint/suspicious/noExplicitAny: window typing for jQuery mock
    (window as any).jQuery = undefined;
  });

  it('injects an inline width style matching the viewport minWidth', async () => {
    createVendorPreviewDom();
    registerCmsPreviewBridge();
    await Promise.resolve();

    setActiveViewport('sm');

    const styleTag = document.getElementById(
      'grid-preview-viewport-override',
    ) as HTMLStyleElement | null;
    expect(styleTag).not.toBeNull();
    expect(styleTag!.textContent).toContain('576px');
  });

  it('falls back to 375px for mobile-first viewports (minWidth 0)', async () => {
    createVendorPreviewDom();
    registerCmsPreviewBridge();
    await Promise.resolve();

    setActiveViewport('xs');

    const styleTag = document.getElementById('grid-preview-viewport-override');
    expect(styleTag?.textContent).toContain('375px');
  });

  it('calls vendor changeSize when jQuery is available', async () => {
    createVendorPreviewDom();

    const changeSize = vi.fn();
    const jqFn = vi.fn(() => ({ length: 1, changeSize }));
    // biome-ignore lint/suspicious/noExplicitAny: window jQuery mock
    (window as any).jQuery = jqFn;

    registerCmsPreviewBridge();
    await Promise.resolve();
    setActiveViewport('md');

    expect(jqFn).toHaveBeenCalledWith('.cms-preview');
    expect(changeSize).toHaveBeenCalledWith('grid-md');
  });

  it('no-ops vendor changeSize path when jQuery is absent', async () => {
    createVendorPreviewDom();
    // biome-ignore lint/suspicious/noExplicitAny: window typing for jQuery mock
    (window as any).jQuery = undefined;

    registerCmsPreviewBridge();
    await Promise.resolve();
    setActiveViewport('md');

    // Inline style still applied — this confirms the fallback path
    const styleTag = document.getElementById('grid-preview-viewport-override');
    expect(styleTag?.textContent).toContain('768px');
  });
});
