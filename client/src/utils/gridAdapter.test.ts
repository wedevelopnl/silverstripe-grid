import { describe, it, expect, beforeEach } from 'vitest';
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
} from './gridAdapter';
import type { GridSettings } from '@/types/elements';

beforeEach(() => {
  resetAdapterCache();
});

describe('getViewports', () => {
  it('returns viewport configs from adapter config', () => {
    const viewports = getViewports();
    expect(viewports).toHaveLength(6);
    expect(viewports[0]).toEqual({ key: 'xs', label: 'Extra small' });
    expect(viewports[2]).toEqual({ key: 'md', label: 'Medium' });
  });
});

describe('getDefaultViewport', () => {
  it('returns the default viewport key', () => {
    expect(getDefaultViewport()).toBe('md');
  });
});

describe('getColumnCount', () => {
  it('returns the column count', () => {
    expect(getColumnCount()).toBe(12);
  });
});

describe('getRowClasses', () => {
  it('returns the row CSS classes', () => {
    expect(getRowClasses()).toBe('row');
  });
});

describe('getOffsetStrategy', () => {
  it('returns the offset strategy', () => {
    expect(getOffsetStrategy()).toBe('margin');
  });
});

describe('getWidthClass', () => {
  it('returns CSS class for a valid width', () => {
    expect(getWidthClass(6)).toBe('col-6');
  });

  it('returns empty string for unmapped width', () => {
    expect(getWidthClass(99)).toBe('');
  });
});

describe('getOffsetClass', () => {
  it('returns CSS class for a valid offset', () => {
    expect(getOffsetClass(3)).toBe('offset-3');
  });

  it('returns empty string for unmapped offset', () => {
    expect(getOffsetClass(99)).toBe('');
  });
});

describe('getWidthOptions', () => {
  it('generates options from 1 to columnCount plus hidden', () => {
    const options = getWidthOptions();
    expect(options).toHaveLength(13); // 12 widths + hidden
    expect(options[0]).toEqual({ value: 1, label: '1/12' });
    expect(options[11]).toEqual({ value: 12, label: '12/12' });
    expect(options[12]).toEqual({ value: 'hidden', label: 'hidden' });
  });

  it('caches the result across calls', () => {
    const first = getWidthOptions();
    const second = getWidthOptions();
    expect(first).toBe(second);
  });
});

describe('getOffsetOptions', () => {
  it('generates options from 0 to columnCount - 1 when no width given', () => {
    const options = getOffsetOptions();
    expect(options).toHaveLength(12); // 0 to 11
    expect(options[0]).toEqual({ value: 0, label: 'none' });
    expect(options[1]).toEqual({ value: 1, label: '+1' });
  });

  it('limits max offset based on current width', () => {
    const options = getOffsetOptions(10);
    expect(options).toHaveLength(3); // 0, 1, 2
    expect(options[2]).toEqual({ value: 2, label: '+2' });
  });
});

describe('resolveViewportSettings', () => {
  const settings: GridSettings = {
    default: { width: 12, offset: 0, visible: true },
    overrides: {
      sm: { width: 6, offset: 3, visible: true },
    },
  };

  it('returns override settings when viewport has an override', () => {
    expect(resolveViewportSettings(settings, 'sm')).toEqual({
      width: 6,
      offset: 3,
      visible: true,
    });
  });

  it('returns default settings when viewport has no override', () => {
    expect(resolveViewportSettings(settings, 'lg')).toEqual({
      width: 12,
      offset: 0,
      visible: true,
    });
  });
});
