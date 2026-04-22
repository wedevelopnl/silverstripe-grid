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

  it('injects a stylesheet with width + height rules per viewport on mount', async () => {
    createVendorPreviewDom();
    registerCmsPreviewBridge();
    await Promise.resolve();

    const styleTag = document.getElementById(
      'grid-preview-viewport-styles',
    ) as HTMLStyleElement | null;
    expect(styleTag).not.toBeNull();
    const content = styleTag!.textContent ?? '';

    // Monotonic height formula: min(900, max(500, round(width * 0.75)))
    // xs (minWidth 0 → 375 mobile-first fallback): clamped to 500 floor
    expect(content).toContain('.cms-preview.grid-xs .preview-device-outer');
    expect(content).toContain('width: 375px');
    expect(content).toMatch(/grid-xs[^}]*height: 500px/s);

    // sm (576): 576 * 0.75 = 432, clamped to 500
    expect(content).toContain('.cms-preview.grid-sm .preview-device-outer');
    expect(content).toContain('width: 576px');
    expect(content).toMatch(/grid-sm[^}]*height: 500px/s);

    // md (768): 768 * 0.75 = 576 (above the 500 floor)
    expect(content).toContain('.cms-preview.grid-md .preview-device-outer');
    expect(content).toContain('width: 768px');
    expect(content).toMatch(/grid-md[^}]*height: 576px/s);

    // Dimension readout label reflects both axes
    expect(content).toContain('375px × 500px');
    expect(content).toContain('768px × 576px');
  });

  it('applies vendor frame class + our grid-<key> class on viewport change', async () => {
    createVendorPreviewDom();

    const entwineChangeSize = vi.fn();
    const entwineNs = { changeSize: entwineChangeSize };
    const plainRemoveClass = vi.fn();
    const plainAddClass = vi.fn();
    const selection = {
      length: 1,
      entwine: vi.fn((namespace: string) => (namespace === 'ss.preview' ? entwineNs : {})),
      removeClass: plainRemoveClass,
      addClass: plainAddClass,
    };
    const jqFn = vi.fn(() => selection);
    // biome-ignore lint/suspicious/noExplicitAny: window jQuery mock
    (window as any).jQuery = jqFn;

    registerCmsPreviewBridge();
    await Promise.resolve();
    setActiveViewport('md');

    // Vendor changeSize is called with the vendor carrier class — NOT with
    // our grid-<key>. That's what triggers the device-frame styling.
    expect(entwineChangeSize).toHaveBeenCalledWith('tablet');
    // Then we strip any prior grid-<key> class and add the new one so our
    // width override wins the cascade.
    expect(plainRemoveClass).toHaveBeenCalledWith('grid-xs grid-sm grid-md');
    expect(plainAddClass).toHaveBeenCalledWith('grid-md');
  });

  it('cleans up on teardown — removes stylesheet, restores auto, strips grid-<key>', async () => {
    createVendorPreviewDom();

    const entwineChangeSize = vi.fn();
    const plainRemoveClass = vi.fn();
    const plainAddClass = vi.fn();
    const selection = {
      length: 1,
      entwine: vi.fn(() => ({ changeSize: entwineChangeSize })),
      removeClass: plainRemoveClass,
      addClass: plainAddClass,
    };
    const jqFn = vi.fn(() => selection);
    // biome-ignore lint/suspicious/noExplicitAny: window jQuery mock
    (window as any).jQuery = jqFn;

    registerCmsPreviewBridge();
    await Promise.resolve();

    teardownCmsPreviewBridge();

    expect(document.getElementById('grid-preview-viewport-styles')).toBeNull();
    // Auto is restored via vendor changeSize.
    expect(entwineChangeSize).toHaveBeenLastCalledWith('auto');
    // grid-* classes are then stripped off.
    expect(plainRemoveClass).toHaveBeenCalledWith('grid-xs grid-sm grid-md');
  });

  it('no-ops vendor changeSize path when jQuery is absent — stylesheet still injected', async () => {
    createVendorPreviewDom();
    // biome-ignore lint/suspicious/noExplicitAny: window typing for jQuery mock
    (window as any).jQuery = undefined;

    registerCmsPreviewBridge();
    await Promise.resolve();
    setActiveViewport('md');

    // Without jQuery we can't toggle the .grid-<key> class, so the preview
    // won't actually resize — but the stylesheet is still injected for the
    // general case. No error thrown.
    const styleTag = document.getElementById('grid-preview-viewport-styles');
    expect(styleTag).not.toBeNull();
  });
});
