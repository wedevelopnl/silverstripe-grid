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

vi.mock('@/api/config', () => ({
  getAdapterConfig: () => ({
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
  }),
}));

beforeEach(() => {
  resetAdapterCache();
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
  it('returns defaults for empty settings', () => {
    expect(resolveViewportSettings({}, 'md')).toEqual({
      width: 12,
      offset: 0,
      visible: true,
    });
  });

  it('returns explicit settings for the active viewport', () => {
    const settings = {
      md: { width: 6, offset: 2, visible: true },
    };

    expect(resolveViewportSettings(settings, 'md')).toEqual({
      width: 6,
      offset: 2,
      visible: true,
    });
  });

  it('cascades from smaller viewport to larger active viewport', () => {
    const settings = {
      sm: { width: 8, offset: 1, visible: true },
    };

    // lg inherits sm settings via cascade
    expect(resolveViewportSettings(settings, 'lg')).toEqual({
      width: 8,
      offset: 1,
      visible: true,
    });
  });

  it('does not look ahead past active viewport', () => {
    const settings = {
      lg: { width: 4, offset: 0, visible: true },
    };

    // sm is before lg — should not see lg's override
    expect(resolveViewportSettings(settings, 'sm')).toEqual({
      width: 12,
      offset: 0,
      visible: true,
    });
  });

  it('cascades hidden visibility', () => {
    const settings = {
      md: { width: 6, offset: 0, visible: false },
    };

    // lg inherits md's hidden state
    expect(resolveViewportSettings(settings, 'lg')).toEqual({
      width: 6,
      offset: 0,
      visible: false,
    });
  });

  it('accumulates overrides across multiple viewports', () => {
    const settings = {
      sm: { width: 8, offset: 0, visible: true },
      md: { width: 6, offset: 2, visible: true },
    };

    expect(resolveViewportSettings(settings, 'md')).toEqual({
      width: 6,
      offset: 2,
      visible: true,
    });
  });

  it('partially overrides cascaded values', () => {
    const settings = {
      sm: { width: 8, offset: 3, visible: true },
      lg: { width: 4, offset: 3, visible: true },
    };

    // md inherits sm's settings (cascade stops before lg)
    expect(resolveViewportSettings(settings, 'md')).toEqual({
      width: 8,
      offset: 3,
      visible: true,
    });

    // xl inherits lg's width override, with sm's offset still cascaded
    expect(resolveViewportSettings(settings, 'xl')).toEqual({
      width: 4,
      offset: 3,
      visible: true,
    });
  });
});
