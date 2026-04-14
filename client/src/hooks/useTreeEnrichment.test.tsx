import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { buildStorageKey, useTreeEnrichment } from '@/hooks/useTreeEnrichment';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  resetIdCounter,
} from '@/testing/factories';
import type { SectionNode } from '@/types/elements';

// Use a unique areaId per test to avoid localStorage leaking between tests
let areaId: number;

// jsdom's localStorage is a Proxy that doesn't support spyOn/property assignment.
// Keep a backing store and replace the entire global for localStorage persistence tests.
const realLocalStorage = globalThis.localStorage;

function createMockLocalStorage(): Storage & {
  _store: Map<string, string>;
  getItemSpy: ReturnType<typeof vi.fn>;
  setItemSpy: ReturnType<typeof vi.fn>;
} {
  const store = new Map<string, string>();
  const getItemSpy = vi.fn((key: string): string | null => store.get(key) ?? null);
  const setItemSpy = vi.fn((key: string, value: string): void => {
    store.set(key, value);
  });

  return {
    _store: store,
    getItemSpy,
    setItemSpy,
    get length() {
      return store.size;
    },
    clear: () => {
      store.clear();
    },
    getItem: getItemSpy,
    setItem: setItemSpy,
    removeItem: (key: string) => {
      store.delete(key);
    },
    key: (index: number) => [...store.keys()][index] ?? null,
  };
}

beforeEach(() => {
  resetIdCounter();
  areaId = Math.floor(Math.random() * 1_000_000);
});

afterEach(() => {
  // Restore real localStorage if it was replaced
  Object.defineProperty(globalThis, 'localStorage', {
    value: realLocalStorage,
    writable: true,
    configurable: true,
  });
});

function renderEnrichment(sections: readonly SectionNode[], testAreaId = areaId) {
  return renderHook(() => useTreeEnrichment(sections, testAreaId));
}

describe('useTreeEnrichment', () => {
  describe('section toggle', () => {
    it('should toggle section collapse state', () => {
      const section = createSectionNode({ id: 10 });
      const { result } = renderEnrichment([section]);

      expect(result.current[0].isCollapsed).toBe(false);

      act(() => {
        result.current[0].toggle();
      });
      expect(result.current[0].isCollapsed).toBe(true);

      act(() => {
        result.current[0].toggle();
      });
      expect(result.current[0].isCollapsed).toBe(false);
    });
  });

  describe('row toggle', () => {
    it('should toggle row collapse state', () => {
      const section = createSectionNode({
        id: 10,
        children: [createRowNode({ id: 20 })],
      });
      const { result } = renderEnrichment([section]);

      const row = result.current[0].children![0];
      expect(row.isCollapsed).toBe(false);

      act(() => {
        row.toggle();
      });

      expect(result.current[0].children![0].isCollapsed).toBe(true);
    });

    it('should toggle row back to uncollapsed', () => {
      const section = createSectionNode({
        id: 10,
        children: [createRowNode({ id: 20 })],
      });
      const { result } = renderEnrichment([section]);

      act(() => {
        result.current[0].children![0].toggle();
      });
      expect(result.current[0].children![0].isCollapsed).toBe(true);

      act(() => {
        result.current[0].children![0].toggle();
      });
      expect(result.current[0].children![0].isCollapsed).toBe(false);
    });
  });

  describe('column toggle', () => {
    it('should toggle column collapse state', () => {
      const section = createSectionNode({
        id: 10,
        children: [
          createRowNode({
            id: 20,
            children: [createColumnNode({ id: 30 })],
          }),
        ],
      });
      const { result } = renderEnrichment([section]);

      const column = result.current[0].children![0].children![0];
      expect(column.isCollapsed).toBe(false);

      act(() => {
        column.toggle();
      });

      expect(result.current[0].children![0].children![0].isCollapsed).toBe(true);
    });

    it('should toggle column back to uncollapsed', () => {
      const section = createSectionNode({
        id: 10,
        children: [
          createRowNode({
            id: 20,
            children: [createColumnNode({ id: 30 })],
          }),
        ],
      });
      const { result } = renderEnrichment([section]);

      act(() => {
        result.current[0].children![0].children![0].toggle();
      });
      expect(result.current[0].children![0].children![0].isCollapsed).toBe(true);

      act(() => {
        result.current[0].children![0].children![0].toggle();
      });
      expect(result.current[0].children![0].children![0].isCollapsed).toBe(false);
    });
  });

  describe('sortableId assignment', () => {
    it('should assign correct sortableIds to all node types', () => {
      const section = createSectionNode({
        id: 1,
        children: [
          createRowNode({
            id: 2,
            children: [
              createColumnNode({
                id: 3,
                childCount: 1,
              }),
            ],
          }),
        ],
      });

      // Override the auto-generated child element ID for predictability
      section.children![0].children![0].children![0].id = 4;

      const { result } = renderEnrichment([section]);

      expect(result.current[0].sortableId).toBe('section-1');
      expect(result.current[0].children![0].sortableId).toBe('row-2');
      expect(result.current[0].children![0].children![0].sortableId).toBe('column-3');
      expect(result.current[0].children![0].children![0].children![0].sortableId).toBe('element-4');
    });
  });

  describe('childSortableIds', () => {
    it('should populate childSortableIds from children', () => {
      const section = createSectionNode({
        id: 1,
        children: [createRowNode({ id: 10 }), createRowNode({ id: 11 })],
      });

      const { result } = renderEnrichment([section]);

      expect(result.current[0].childSortableIds).toEqual(['row-10', 'row-11']);
    });

    it('should populate row childSortableIds from column children', () => {
      const section = createSectionNode({
        id: 1,
        children: [
          createRowNode({
            id: 10,
            children: [createColumnNode({ id: 30 }), createColumnNode({ id: 31 })],
          }),
        ],
      });

      const { result } = renderEnrichment([section]);

      expect(result.current[0].children![0].childSortableIds).toEqual(['column-30', 'column-31']);
    });

    it('should populate column childSortableIds from element children', () => {
      const section = createSectionNode({
        id: 1,
        children: [
          createRowNode({
            id: 10,
            children: [createColumnNode({ id: 30, childCount: 2 })],
          }),
        ],
      });

      // Set predictable IDs on the auto-generated children
      section.children![0].children![0].children![0].id = 100;
      section.children![0].children![0].children![1].id = 101;

      const { result } = renderEnrichment([section]);

      expect(result.current[0].children![0].children![0].childSortableIds).toEqual([
        'element-100',
        'element-101',
      ]);
    });

    it('should return empty array when children is null', () => {
      const section = createSectionNode({ id: 1, children: null });
      const { result } = renderEnrichment([section]);

      expect(result.current[0].childSortableIds).toEqual([]);
    });
  });

  describe('stable toggle callbacks', () => {
    it('returns referentially stable toggle callbacks when sections reference changes', () => {
      const buildSections = (): SectionNode[] => [
        createSectionNode({
          id: 10,
          children: [
            createRowNode({
              id: 20,
              children: [createColumnNode({ id: 30 })],
            }),
          ],
        }),
      ];

      // Reset factory counter so both renders produce the same IDs
      resetIdCounter();
      const first = buildSections();
      const { result, rerender } = renderHook(
        ({ s }: { s: readonly SectionNode[] }) => useTreeEnrichment(s, areaId),
        { initialProps: { s: first } },
      );

      const firstSectionToggle = result.current[0].toggle;
      const firstRowToggle = result.current[0].children![0].toggle;
      const firstColumnToggle = result.current[0].children![0].children![0].toggle;

      // New sections array (fresh identity) — forces useMemo to re-run enrichment
      resetIdCounter();
      const second = buildSections();
      rerender({ s: second });

      expect(result.current[0].toggle).toBe(firstSectionToggle);
      expect(result.current[0].children![0].toggle).toBe(firstRowToggle);
      expect(result.current[0].children![0].children![0].toggle).toBe(firstColumnToggle);
    });

    it('returns stable toggles after collapsedIds change', () => {
      const section = createSectionNode({
        id: 10,
        children: [
          createRowNode({
            id: 20,
            children: [createColumnNode({ id: 30 })],
          }),
        ],
      });
      const { result } = renderEnrichment([section]);

      const firstColumnToggle = result.current[0].children![0].children![0].toggle;

      // Toggle the column — changes collapsedIds state, re-runs useMemo
      act(() => {
        result.current[0].children![0].children![0].toggle();
      });

      expect(result.current[0].children![0].children![0].toggle).toBe(firstColumnToggle);
    });
  });

  describe('buildStorageKey', () => {
    it('should produce key in format grid:collapsed:{areaId}', () => {
      expect(buildStorageKey(42)).toBe('grid:collapsed:42');
    });

    it('should convert areaId to string in the key', () => {
      expect(buildStorageKey(0)).toBe('grid:collapsed:0');
    });
  });

  describe('localStorage persistence', () => {
    it('should filter out non-number values from localStorage', () => {
      const mock = createMockLocalStorage();
      const testAreaId = 55;
      mock._store.set(`grid:collapsed:${testAreaId}`, JSON.stringify([1, 'two', null, 3]));
      Object.defineProperty(globalThis, 'localStorage', {
        value: mock,
        writable: true,
        configurable: true,
      });

      const section = createSectionNode({ id: 1 });
      const { result } = renderEnrichment([section], testAreaId);

      // IDs 1 and 3 are in the collapsed set; section with id=1 should be collapsed
      expect(result.current[0].isCollapsed).toBe(true);
    });

    it('should return empty set when localStorage contains non-array JSON', () => {
      const mock = createMockLocalStorage();
      const testAreaId = 56;
      mock._store.set(`grid:collapsed:${testAreaId}`, JSON.stringify({ a: 1 }));
      Object.defineProperty(globalThis, 'localStorage', {
        value: mock,
        writable: true,
        configurable: true,
      });

      const section = createSectionNode({ id: 1 });
      const { result } = renderEnrichment([section], testAreaId);

      // Non-array should be treated as empty — nothing collapsed
      expect(result.current[0].isCollapsed).toBe(false);
    });

    it('should persist collapsed IDs to localStorage on toggle', () => {
      const mock = createMockLocalStorage();
      Object.defineProperty(globalThis, 'localStorage', {
        value: mock,
        writable: true,
        configurable: true,
      });
      const testAreaId = 57;

      const section = createSectionNode({ id: 10 });
      const { result } = renderEnrichment([section], testAreaId);

      act(() => {
        result.current[0].toggle();
      });

      expect(mock.setItemSpy).toHaveBeenCalledWith(
        `grid:collapsed:${testAreaId}`,
        JSON.stringify([10]),
      );
    });
  });
});
