import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useTreeEnrichment, buildStorageKey } from './useTreeEnrichment';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  resetIdCounter,
} from '@/testing/factories';

// jsdom's localStorage in Node 24 doesn't have standard methods.
// Provide a simple in-memory stub.
let storage: Map<string, string>;
const localStorageStub = {
  getItem: (key: string) => storage.get(key) ?? null,
  setItem: (key: string, value: string) => { storage.set(key, value); },
  removeItem: (key: string) => { storage.delete(key); },
  clear: () => { storage.clear(); },
  get length() { return storage.size; },
  key: (index: number) => [...storage.keys()][index] ?? null,
};

beforeEach(() => {
  resetIdCounter();
  storage = new Map();
  Object.defineProperty(globalThis, 'localStorage', {
    value: localStorageStub,
    writable: true,
    configurable: true,
  });
});

describe('buildStorageKey', () => {
  it('returns a key scoped to the areaId', () => {
    expect(buildStorageKey(42)).toBe('grid:collapsed:42');
  });

  it('returns different keys for different areaIds', () => {
    expect(buildStorageKey(1)).not.toBe(buildStorageKey(2));
  });
});

describe('useTreeEnrichment', () => {
  it('adds sortableId to section nodes', () => {
    const section = createSectionNode({ id: 10 });

    const { result } = renderHook(() => useTreeEnrichment([section], 1));

    expect(result.current[0].sortableId).toBe('section-10');
  });

  it('adds sortableId to row nodes', () => {
    const row = createRowNode({ id: 20, parentId: 10 });
    const section = createSectionNode({ id: 10, children: [row] });

    const { result } = renderHook(() => useTreeEnrichment([section], 1));

    expect(result.current[0].children![0].sortableId).toBe('row-20');
  });

  it('adds sortableId to column nodes', () => {
    const col = createColumnNode({ id: 30, parentId: 20 });
    const row = createRowNode({ id: 20, parentId: 10, children: [col] });
    const section = createSectionNode({ id: 10, children: [row] });

    const { result } = renderHook(() => useTreeEnrichment([section], 1));

    expect(result.current[0].children![0].children![0].sortableId).toBe('column-30');
  });

  it('adds sortableId to simple element nodes', () => {
    const section = createSectionNode({ id: 10 });
    // Default factory creates section > row > column > element
    const { result } = renderHook(() => useTreeEnrichment([section], 1));

    const element = result.current[0].children![0].children![0].children![0];
    expect(element.sortableId).toMatch(/^element-\d+$/);
  });

  it('adds childSortableIds to container nodes', () => {
    const col1 = createColumnNode({ id: 30, parentId: 20, childCount: 0 });
    const col2 = createColumnNode({ id: 31, parentId: 20, childCount: 0 });
    const row = createRowNode({ id: 20, parentId: 10, children: [col1, col2] });
    const section = createSectionNode({ id: 10, children: [row] });

    const { result } = renderHook(() => useTreeEnrichment([section], 1));

    expect(result.current[0].childSortableIds).toEqual(['row-20']);
    expect(result.current[0].children![0].childSortableIds).toEqual(['column-30', 'column-31']);
  });

  describe('collapse toggle', () => {
    it('toggles collapse state on a section', () => {
      const section = createSectionNode({ id: 10 });

      const { result } = renderHook(() => useTreeEnrichment([section], 1));

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

    it('persists collapsed state to localStorage', () => {
      const section = createSectionNode({ id: 10 });
      const setItemSpy = vi.spyOn(localStorageStub, 'setItem');

      const { result } = renderHook(() => useTreeEnrichment([section], 1));

      act(() => {
        result.current[0].toggle();
      });

      expect(setItemSpy).toHaveBeenCalledWith(
        'grid:collapsed:1',
        expect.any(String),
      );

      const stored = JSON.parse(setItemSpy.mock.calls[0][1]);
      expect(stored).toContain(10);
    });

    it('reads collapsed state from localStorage on init', () => {
      localStorage.setItem('grid:collapsed:1', JSON.stringify([10]));

      const section = createSectionNode({ id: 10 });
      const { result } = renderHook(() => useTreeEnrichment([section], 1));

      expect(result.current[0].isCollapsed).toBe(true);
    });
  });

  describe('corrupted localStorage', () => {
    it('recovers from non-array JSON', () => {
      localStorage.setItem('grid:collapsed:1', '"not-an-array"');

      const section = createSectionNode({ id: 10 });
      const { result } = renderHook(() => useTreeEnrichment([section], 1));

      expect(result.current[0].isCollapsed).toBe(false);
    });

    it('recovers from invalid JSON', () => {
      localStorage.setItem('grid:collapsed:1', '{broken');

      const section = createSectionNode({ id: 10 });
      const { result } = renderHook(() => useTreeEnrichment([section], 1));

      expect(result.current[0].isCollapsed).toBe(false);
    });

    it('filters out non-number values from stored array', () => {
      localStorage.setItem('grid:collapsed:1', JSON.stringify([10, 'bogus', null, true]));

      const section = createSectionNode({ id: 10 });
      const { result } = renderHook(() => useTreeEnrichment([section], 1));

      // 10 is a valid number, so it should be collapsed
      expect(result.current[0].isCollapsed).toBe(true);
    });
  });

  describe('per-area isolation', () => {
    it('uses different storage keys for different areaIds', () => {
      localStorage.setItem('grid:collapsed:1', JSON.stringify([10]));

      const section = createSectionNode({ id: 10 });

      const { result: area1 } = renderHook(() => useTreeEnrichment([section], 1));
      const { result: area2 } = renderHook(() => useTreeEnrichment([section], 2));

      expect(area1.current[0].isCollapsed).toBe(true);
      expect(area2.current[0].isCollapsed).toBe(false);
    });
  });
});
