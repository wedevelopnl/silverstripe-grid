import {
  getViewports,
  getDefaultViewport,
  getColumnCount,
  getRowClasses,
  getOffsetStrategy,
  getWidthClass,
  getOffsetClass,
  getWidthOptions,
  getOffsetOptions,
  resolveViewportSettings,
  resetAdapterCache,
} from '@/utils/gridAdapter';

const mockGetAdapterConfig = vi.fn(() => ({
  viewports: [
    { key: 'xs', label: 'Extra Small' },
    { key: 'sm', label: 'Small' },
    { key: 'md', label: 'Medium' },
    { key: 'lg', label: 'Large' },
    { key: 'xl', label: 'Extra Large' },
    { key: 'xxl', label: 'Extra Extra Large' },
  ],
  defaultViewport: 'md',
  columnCount: 12,
  rowClasses: 'row',
  offsetStrategy: 'margin',
  baseWidthClasses: Object.fromEntries(
    Array.from({ length: 12 }, (_, i) => [String(i + 1), `col-${i + 1}`]),
  ),
  baseOffsetClasses: Object.fromEntries(
    Array.from({ length: 12 }, (_, i) => [String(i), `offset-${i}`]),
  ),
}));

vi.mock('@/api/config', () => ({
  getAdapterConfig: () => mockGetAdapterConfig(),
}));


beforeEach(() => {
  resetAdapterCache();
  mockGetAdapterConfig.mockClear();
});

describe('gridAdapter', () => {
  it('returns the list of viewports from adapter config', () => {
    const viewports = getViewports();
    expect(viewports).toHaveLength(6);
    expect(viewports[0].key).toBe('xs');
  });

  it('returns the default viewport key', () => {
    expect(getDefaultViewport()).toBe('md');
  });

  it('returns the column count from adapter config', () => {
    expect(getColumnCount()).toBe(12);
  });

  it('returns row classes from adapter config', () => {
    expect(getRowClasses()).toBe('row');
  });

  it('returns offset strategy from adapter config', () => {
    expect(getOffsetStrategy()).toBe('margin');
  });

  it('looks up base width class by column width', () => {
    expect(getWidthClass(6)).toBe('col-6');
  });

  it('looks up base offset class by offset value', () => {
    expect(getOffsetClass(3)).toBe('offset-3');
  });

  it('returns the offset-0 class for zero offset', () => {
    expect(getOffsetClass(0)).toBe('offset-0');
  });

  it('returns empty string for unmapped width key', () => {
    expect(getWidthClass(99)).toBe('');
  });

  it('returns empty string for unmapped offset key', () => {
    expect(getOffsetClass(99)).toBe('');
  });

  it('returns width options with columnCount + 1 entries including hidden', () => {
    const options = getWidthOptions();
    expect(options).toHaveLength(13);
    expect(options[0]).toEqual({ value: 1, label: '1/12' });
    expect(options[11]).toEqual({ value: 12, label: '12/12' });
    expect(options[12]).toEqual({ value: 'hidden', label: 'hidden' });
  });

  it('returns all offset options when no width is provided', () => {
    const options = getOffsetOptions();
    expect(options).toHaveLength(12);
    expect(options[0]).toEqual({ value: 0, label: 'none' });
    expect(options[1]).toEqual({ value: 1, label: '+1' });
    expect(options[11]).toEqual({ value: 11, label: '+11' });
  });

  it('constrains offset options based on current width', () => {
    const options = getOffsetOptions(8);
    expect(options).toHaveLength(5);
    expect(options[0]).toEqual({ value: 0, label: 'none' });
    expect(options[4]).toEqual({ value: 4, label: '+4' });
  });

  it('returns only zero offset when width equals column count', () => {
    const options = getOffsetOptions(12);
    expect(options).toHaveLength(1);
    expect(options[0]).toEqual({ value: 0, label: 'none' });
  });
});

describe('resolveViewportSettings', () => {
  const defaults = { width: 12, offset: 0, visible: true };

  it('returns default settings when no overrides exist', () => {
    const settings = { default: defaults, overrides: {} };

    expect(resolveViewportSettings(settings, 'md')).toEqual(defaults);
  });

  it('returns override when one exists for the active viewport', () => {
    const settings = {
      default: defaults,
      overrides: { md: { width: 6, offset: 2, visible: true } },
    };

    expect(resolveViewportSettings(settings, 'md')).toEqual({
      width: 6,
      offset: 2,
      visible: true,
    });
  });

  it('returns default when override exists for a different viewport', () => {
    const settings = {
      default: defaults,
      overrides: { lg: { width: 4, offset: 0, visible: true } },
    };

    expect(resolveViewportSettings(settings, 'md')).toEqual(defaults);
  });

  it('returns override with hidden visibility', () => {
    const settings = {
      default: defaults,
      overrides: { md: { width: 6, offset: 0, visible: false } },
    };

    expect(resolveViewportSettings(settings, 'md')).toEqual({
      width: 6,
      offset: 0,
      visible: false,
    });
  });

  it('returns the correct override when multiple overrides exist', () => {
    const settings = {
      default: defaults,
      overrides: {
        sm: { width: 8, offset: 0, visible: true },
        md: { width: 6, offset: 2, visible: true },
        lg: { width: 4, offset: 1, visible: false },
      },
    };

    expect(resolveViewportSettings(settings, 'sm')).toEqual({
      width: 8,
      offset: 0,
      visible: true,
    });

    expect(resolveViewportSettings(settings, 'md')).toEqual({
      width: 6,
      offset: 2,
      visible: true,
    });

    expect(resolveViewportSettings(settings, 'lg')).toEqual({
      width: 4,
      offset: 1,
      visible: false,
    });
  });

  it('returns default for a viewport without an override even when other overrides exist', () => {
    const settings = {
      default: defaults,
      overrides: {
        sm: { width: 8, offset: 0, visible: true },
        lg: { width: 4, offset: 3, visible: true },
      },
    };

    expect(resolveViewportSettings(settings, 'md')).toEqual(defaults);
    expect(resolveViewportSettings(settings, 'xl')).toEqual(defaults);
  });
});

describe('adapter config caching', () => {
  it('caches config — getAdapterConfig is called only once across multiple reads', () => {
    getViewports();
    getDefaultViewport();
    getColumnCount();

    expect(mockGetAdapterConfig).toHaveBeenCalledTimes(1);
  });

  it('resetAdapterCache forces a fresh read on next access', () => {
    getViewports();
    expect(mockGetAdapterConfig).toHaveBeenCalledTimes(1);

    resetAdapterCache();
    getViewports();
    expect(mockGetAdapterConfig).toHaveBeenCalledTimes(2);
  });

  it('caches width options — repeated calls do not rebuild', () => {
    const first = getWidthOptions();
    const second = getWidthOptions();

    // Same reference proves the cached value was returned
    expect(first).toBe(second);
  });

  it('resetAdapterCache also clears width options cache', () => {
    const first = getWidthOptions();

    resetAdapterCache();

    const second = getWidthOptions();
    // After reset, a new array is built (different reference)
    expect(first).not.toBe(second);
    // But the content is the same
    expect(first).toEqual(second);
  });
});
