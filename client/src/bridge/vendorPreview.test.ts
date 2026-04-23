import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { openVendorPreview } from './vendorPreview';

vi.mock('@/utils/gridAdapter', () => ({
  getViewports: () => [
    { key: 'xs', label: 'Extra Small', minWidth: 0 },
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
  ],
}));

function createVendorDom(): void {
  document.body.innerHTML = `
    <div class="cms-preview">
      <div class="preview-device-outer"></div>
      <span id="preview-size-dropdown" class="preview-size-selector">
        <select id="preview-size-dropdown-select">
          <option value="auto">Auto</option>
        </select>
      </span>
    </div>
  `;
}

interface EntwineMock {
  changeSize: ReturnType<typeof vi.fn>;
}

interface SelectionMock {
  length: number;
  entwine: ReturnType<typeof vi.fn>;
  addClass: ReturnType<typeof vi.fn>;
  removeClass: ReturnType<typeof vi.fn>;
}

function installJQueryMock(opts: { entwineReady?: boolean } = {}): {
  entwineNs: EntwineMock;
  selection: SelectionMock;
  jq: ReturnType<typeof vi.fn>;
} {
  const { entwineReady = true } = opts;
  const entwineNs: EntwineMock = { changeSize: vi.fn() };
  const selection: SelectionMock = {
    length: 1,
    entwine: vi.fn((ns: string) => (ns === 'ss.preview' && entwineReady ? entwineNs : {})),
    addClass: vi.fn(),
    removeClass: vi.fn(),
  };
  const jq = vi.fn(() => selection);
  (window as unknown as { jQuery: unknown }).jQuery = jq;
  return { entwineNs, selection, jq };
}

describe('openVendorPreview', () => {
  beforeEach(() => {
    createVendorDom();
  });

  afterEach(() => {
    document.body.innerHTML = '';
    (window as unknown as { jQuery?: unknown }).jQuery = undefined;
  });

  it('returns null when the vendor DOM is absent', () => {
    document.body.innerHTML = '';
    expect(openVendorPreview()).toBeNull();
  });

  it('takeOver hides the vendor wrapper and inserts a host as a sibling', () => {
    const handle = openVendorPreview();
    expect(handle).not.toBeNull();

    const host = handle!.takeOver('my-host my-host--anchor');

    const wrapper = document.getElementById('preview-size-dropdown') as HTMLElement;
    expect(wrapper.style.display).toBe('none');
    expect(host.className).toBe('my-host my-host--anchor');
    // Host sits immediately before the wrapper in the same parent.
    expect(host.nextElementSibling).toBe(wrapper);
  });

  it('release removes the host and restores wrapper display', () => {
    const handle = openVendorPreview()!;
    const host = handle.takeOver('host');
    expect(host.isConnected).toBe(true);

    handle.release();

    expect(host.isConnected).toBe(false);
    const wrapper = document.getElementById('preview-size-dropdown') as HTMLElement;
    expect(wrapper.style.display).toBe('');
  });

  it('applyViewport calls entwine changeSize("tablet") then swaps the grid-<key> class', () => {
    const { entwineNs, selection } = installJQueryMock();
    const handle = openVendorPreview()!;

    handle.applyViewport('md');

    expect(entwineNs.changeSize).toHaveBeenCalledWith('tablet');
    expect(selection.removeClass).toHaveBeenCalledWith('grid-xs grid-sm grid-md');
    expect(selection.addClass).toHaveBeenCalledWith('grid-md');
  });

  it('applyViewport is a no-op when entwine is not yet ready', () => {
    const { entwineNs, selection } = installJQueryMock({ entwineReady: false });
    const handle = openVendorPreview()!;

    handle.applyViewport('md');

    expect(entwineNs.changeSize).not.toHaveBeenCalled();
    expect(selection.addClass).not.toHaveBeenCalled();
  });

  it('resetToAuto calls changeSize("auto") and strips grid-<key> classes', () => {
    const { entwineNs, selection } = installJQueryMock();
    const handle = openVendorPreview()!;

    handle.resetToAuto();

    expect(entwineNs.changeSize).toHaveBeenCalledWith('auto');
    expect(selection.removeClass).toHaveBeenCalledWith('grid-xs grid-sm grid-md');
  });

  it('whenReady resolves immediately when entwine is ready', async () => {
    installJQueryMock({ entwineReady: true });
    const handle = openVendorPreview()!;

    await expect(handle.whenReady()).resolves.toBeUndefined();
  });

  it('whenReady resolves within one animation frame when entwine is not ready', async () => {
    installJQueryMock({ entwineReady: false });
    const handle = openVendorPreview()!;

    // Uses rAF under the hood; jsdom polyfills it with setImmediate.
    await expect(handle.whenReady()).resolves.toBeUndefined();
  });
});
