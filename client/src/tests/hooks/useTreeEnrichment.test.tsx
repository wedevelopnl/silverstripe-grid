import { renderHook, act } from '@testing-library/react';

import {
  useTreeEnrichment,
  buildStorageKey,
} from '@/hooks/useTreeEnrichment';
import { makeElement, makeColumn, makeRow, makeSection } from '../helpers/elementFactories';

function createMockStorage(): Storage {
  const store = new Map<string, string>();

  return {
    get length() { return store.size; },
    clear: () => store.clear(),
    getItem: (key: string) => store.get(key) ?? null,
    key: (index: number) => [...store.keys()][index] ?? null,
    removeItem: (key: string) => store.delete(key),
    setItem: (key: string, value: string) => store.set(key, value),
  };
}

let mockStorage: Storage;

beforeEach(() => {
  mockStorage = createMockStorage();
  vi.stubGlobal('localStorage', mockStorage);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const AREA_ID = 42;

function storageKey(): string {
  return `grid:collapsed:${String(AREA_ID)}`;
}

describe('buildStorageKey', () => {
  it('returns a key scoped to the area ID', () => {
    expect(buildStorageKey(42)).toBe('grid:collapsed:42');
  });

  it('handles different area IDs', () => {
    expect(buildStorageKey(1)).toBe('grid:collapsed:1');
    expect(buildStorageKey(999)).toBe('grid:collapsed:999');
  });
});

describe('useTreeEnrichment', () => {
  it('defaults all nodes to expanded when no localStorage data', () => {
    const sections = [makeSection(1)];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    expect(result.current[0].isCollapsed).toBe(false);
  });

  it('reads collapsed state from localStorage', () => {
    mockStorage.setItem(storageKey(), JSON.stringify([1, 2]));

    const sections = [
      makeSection(1),
      makeSection(2),
      makeSection(3),
    ];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    expect(result.current[0].isCollapsed).toBe(true);
    expect(result.current[1].isCollapsed).toBe(true);
    expect(result.current[2].isCollapsed).toBe(false);
  });

  it('toggle collapses an expanded node and persists to localStorage', () => {
    const sections = [makeSection(5)];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));
    expect(result.current[0].isCollapsed).toBe(false);

    act(() => { result.current[0].toggle(); });

    expect(result.current[0].isCollapsed).toBe(true);
    const stored = JSON.parse(mockStorage.getItem(storageKey())!) as number[];
    expect(stored).toContain(5);
  });

  it('toggle expands a collapsed node and removes from localStorage', () => {
    mockStorage.setItem(storageKey(), JSON.stringify([5]));
    const sections = [makeSection(5)];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));
    expect(result.current[0].isCollapsed).toBe(true);

    act(() => { result.current[0].toggle(); });

    expect(result.current[0].isCollapsed).toBe(false);
    const stored = JSON.parse(mockStorage.getItem(storageKey())!) as number[];
    expect(stored).not.toContain(5);
  });

  it('preserves other IDs when toggling', () => {
    mockStorage.setItem(storageKey(), JSON.stringify([10, 20, 30]));
    const sections = [makeSection(20)];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    act(() => { result.current[0].toggle(); });

    const stored = JSON.parse(mockStorage.getItem(storageKey())!) as number[];
    expect(stored).toContain(10);
    expect(stored).toContain(30);
    expect(stored).not.toContain(20);
  });

  it('recursively enriches children at every level', () => {
    mockStorage.setItem(storageKey(), JSON.stringify([10, 30]));

    const sections = [
      makeSection(1, [
          makeRow(10, [makeColumn(30)]),
        ]),
    ];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    const section = result.current[0];
    expect(section.isCollapsed).toBe(false);
    expect(typeof section.toggle).toBe('function');

    const row = section.children![0];
    expect(row.isCollapsed).toBe(true);
    expect(typeof row.toggle).toBe('function');

    const column = row.children![0];
    expect(column.isCollapsed).toBe(true);
    expect(typeof column.toggle).toBe('function');
  });

  it('handles null children', () => {
    const sections = [
      makeSection(1, null),
    ];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    expect(result.current[0].children).toBeNull();
  });

  it('handles corrupted localStorage gracefully (non-JSON)', () => {
    mockStorage.setItem(storageKey(), 'not-json');

    const sections = [makeSection(1)];
    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    expect(result.current[0].isCollapsed).toBe(false);
  });

  it('handles corrupted localStorage gracefully (non-array)', () => {
    mockStorage.setItem(storageKey(), JSON.stringify({ foo: 'bar' }));

    const sections = [makeSection(1)];
    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    expect(result.current[0].isCollapsed).toBe(false);
  });

  it('filters non-number values from localStorage array', () => {
    mockStorage.setItem(storageKey(), JSON.stringify([1, 'two', null, 3]));

    const sections = [makeSection(1), makeSection(3)];
    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    expect(result.current[0].isCollapsed).toBe(true);
    expect(result.current[1].isCollapsed).toBe(true);
  });

  it('handles localStorage.getItem throwing', () => {
    vi.spyOn(mockStorage, 'getItem').mockImplementation(() => {
      throw new Error('SecurityError');
    });

    const sections = [makeSection(1)];
    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    expect(result.current[0].isCollapsed).toBe(false);
  });

  it('handles localStorage.setItem throwing', () => {
    vi.spyOn(mockStorage, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceededError');
    });

    const sections = [makeSection(1)];
    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    act(() => { result.current[0].toggle(); });

    expect(result.current[0].isCollapsed).toBe(true);
  });

  it('scopes storage per areaId', () => {
    mockStorage.setItem('grid:collapsed:10', JSON.stringify([1]));
    mockStorage.setItem('grid:collapsed:20', JSON.stringify([2]));

    const sections = [makeSection(1), makeSection(2)];

    const area10 = renderHook(() => useTreeEnrichment(sections, 10));
    const area20 = renderHook(() => useTreeEnrichment(sections, 20));

    expect(area10.result.current[0].isCollapsed).toBe(true);
    expect(area10.result.current[1].isCollapsed).toBe(false);

    expect(area20.result.current[0].isCollapsed).toBe(false);
    expect(area20.result.current[1].isCollapsed).toBe(true);
  });

  it('returns empty array for empty sections input', () => {
    const { result } = renderHook(() => useTreeEnrichment([], AREA_ID));

    expect(result.current).toEqual([]);
  });

  it('child toggle updates the correct node', () => {
    const sections = [
      makeSection(1, [
          makeRow(10, [makeColumn(30)]),
        ]),
    ];

    const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

    act(() => { result.current[0].children![0].children![0].toggle(); });

    expect(result.current[0].isCollapsed).toBe(false);
    expect(result.current[0].children![0].isCollapsed).toBe(false);
    expect(result.current[0].children![0].children![0].isCollapsed).toBe(true);

    const stored = JSON.parse(mockStorage.getItem(storageKey())!) as number[];
    expect(stored).toContain(30);
    expect(stored).not.toContain(1);
    expect(stored).not.toContain(10);
  });

  describe('sortable ID enrichment', () => {
    it('computes sortableId for sections', () => {
      const sections = [makeSection(7)];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].sortableId).toBe('section-7');
    });

    it('computes sortableId for rows', () => {
      const sections = [
        makeSection(1, [makeRow(15)]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].children![0].sortableId).toBe('row-15');
    });

    it('computes sortableId for columns', () => {
      const sections = [
        makeSection(1, [
            makeRow(10, [makeColumn(25)]),
          ]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].children![0].children![0].sortableId).toBe('column-25');
    });

    it('computes sortableId for leaf elements', () => {
      const sections = [
        makeSection(1, [
            makeRow(10, [
                makeColumn(20, [makeElement(99, 100)]),
              ]),
          ]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      const element = result.current[0].children![0].children![0].children![0];
      expect(element.sortableId).toBe('element-99');
    });

    it('computes childSortableIds for sections from row children', () => {
      const sections = [
        makeSection(1, [makeRow(10), makeRow(20)]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].childSortableIds).toEqual(['row-10', 'row-20']);
    });

    it('computes childSortableIds for rows from column children', () => {
      const sections = [
        makeSection(1, [
            makeRow(10, [makeColumn(30), makeColumn(31)]),
          ]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].children![0].childSortableIds).toEqual(['column-30', 'column-31']);
    });

    it('computes childSortableIds for columns from element children', () => {
      const sections = [
        makeSection(1, [
            makeRow(10, [
                makeColumn(20, [makeElement(50, 100), makeElement(51, 100)]),
              ]),
          ]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].children![0].children![0].childSortableIds).toEqual(['element-50', 'element-51']);
    });

    it('returns empty childSortableIds when section children is null', () => {
      const sections = [makeSection(1, null)];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].childSortableIds).toEqual([]);
    });

    it('returns empty childSortableIds when row children is null', () => {
      const sections = [
        makeSection(1, [makeRow(10, null)]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].children![0].childSortableIds).toEqual([]);
    });

    it('returns empty childSortableIds when column children is null', () => {
      const sections = [
        makeSection(1, [
            makeRow(10, [makeColumn(20, null)]),
          ]),
      ];

      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      expect(result.current[0].children![0].children![0].childSortableIds).toEqual([]);
    });
  });

  describe('non-number filtering in localStorage', () => {
    it('does not persist non-number values from corrupted localStorage after toggle', () => {
      // Seed with mixed types including non-numbers
      mockStorage.setItem(storageKey(), JSON.stringify([1, 'stale-string', null, true]));

      const sections = [makeSection(1)];
      const { result } = renderHook(() => useTreeEnrichment(sections, AREA_ID));

      // Section 1 should be collapsed (ID 1 is a valid number in the set)
      expect(result.current[0].isCollapsed).toBe(true);

      // Toggle section 1 off (uncollapse)
      act(() => { result.current[0].toggle(); });

      // After toggle, the persisted data should only contain numbers.
      // If the filter were removed, non-number values would survive into
      // the new Set and get serialized back to localStorage.
      const stored = JSON.parse(mockStorage.getItem(storageKey())!) as unknown[];
      for (const value of stored) {
        expect(typeof value).toBe('number');
      }
    });
  });
});
