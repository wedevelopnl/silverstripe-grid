import { adapterConfigSchema } from '@/types/adapter';

describe('adapterConfigSchema', () => {
  it('parses valid adapter config', () => {
    const input = {
      viewports: [
        { key: 'xs', label: 'Extra Small' },
        { key: 'md', label: 'Medium' },
      ],
      defaultViewport: 'md',
      columnCount: 12,
      rowClasses: 'row',
      offsetStrategy: 'margin',
      baseWidthClasses: { '1': 'col-1', '2': 'col-2', '12': 'col-12' },
      baseOffsetClasses: { '0': 'offset-0', '1': 'offset-1', '11': 'offset-11' },
    };

    const result = adapterConfigSchema.parse(input);
    expect(result.defaultViewport).toBe('md');
    expect(result.columnCount).toBe(12);
    expect(result.baseWidthClasses['12']).toBe('col-12');
  });

  it('rejects missing required fields', () => {
    expect(() => adapterConfigSchema.parse({})).toThrow();
  });

  it('rejects non-integer columnCount', () => {
    const input = {
      viewports: [],
      defaultViewport: 'md',
      columnCount: 12.5,
      rowClasses: 'row',
      offsetStrategy: 'margin',
      baseWidthClasses: {},
      baseOffsetClasses: {},
    };

    expect(() => adapterConfigSchema.parse(input)).toThrow();
  });

  it('rejects non-positive columnCount', () => {
    const input = {
      viewports: [],
      defaultViewport: 'md',
      columnCount: 0,
      rowClasses: 'row',
      offsetStrategy: 'margin',
      baseWidthClasses: {},
      baseOffsetClasses: {},
    };

    expect(() => adapterConfigSchema.parse(input)).toThrow();
  });

  it('validates viewport items have key and label', () => {
    const input = {
      viewports: [{ key: 'md' }],
      defaultViewport: 'md',
      columnCount: 12,
      rowClasses: 'row',
      offsetStrategy: 'margin',
      baseWidthClasses: {},
      baseOffsetClasses: {},
    };

    expect(() => adapterConfigSchema.parse(input)).toThrow();
  });

  it('parses grid-placement offset strategy', () => {
    const input = {
      viewports: [{ key: 'sm', label: 'Small' }],
      defaultViewport: 'sm',
      columnCount: 12,
      rowClasses: 'grid grid-cols-12',
      offsetStrategy: 'grid-placement',
      baseWidthClasses: { '1': 'col-span-1' },
      baseOffsetClasses: { '0': 'col-start-1' },
    };

    const result = adapterConfigSchema.parse(input);
    expect(result.offsetStrategy).toBe('grid-placement');
  });

  it('rejects invalid offset strategy value', () => {
    const input = {
      viewports: [],
      defaultViewport: 'md',
      columnCount: 12,
      rowClasses: 'row',
      offsetStrategy: 'absolute',
      baseWidthClasses: {},
      baseOffsetClasses: {},
    };

    expect(() => adapterConfigSchema.parse(input)).toThrow();
  });
});
