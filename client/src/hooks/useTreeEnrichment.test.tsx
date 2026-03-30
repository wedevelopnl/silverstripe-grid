import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useTreeEnrichment } from '@/hooks/useTreeEnrichment';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  resetIdCounter,
} from '@/testing/factories';
import type { SectionNode } from '@/types/elements';

// Use a unique areaId per test to avoid localStorage leaking between tests
let areaId: number;

beforeEach(() => {
  resetIdCounter();
  areaId = Math.floor(Math.random() * 1_000_000);
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
        children: [
          createRowNode({ id: 10 }),
          createRowNode({ id: 11 }),
        ],
      });

      const { result } = renderEnrichment([section]);

      expect(result.current[0].childSortableIds).toEqual(['row-10', 'row-11']);
    });

    it('should return empty array when children is null', () => {
      const section = createSectionNode({ id: 1, children: null });
      const { result } = renderEnrichment([section]);

      expect(result.current[0].childSortableIds).toEqual([]);
    });
  });
});
