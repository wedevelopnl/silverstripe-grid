import { describe, it, expect } from 'vitest';
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories';
import type { ColumnNode, RowNode, SectionNode, SimpleElementNode } from '@/types/elements';
import { buildNodeKey } from '@/types/identity';
import { applyReorder } from './applyReorder';

describe('applyReorder', () => {
  describe('same-container reorder', () => {
    it('moves an element to a later position within the same parent', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const e2 = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const e3 = createSimpleElement({ id: 12, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [e1, e2, e3] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        buildNodeKey('element', 10),
        buildNodeKey('column', 30),
        buildNodeKey('element', 12),
      );

      const movedColumn = ((result.nodes[0] as SectionNode).children?.[0] as RowNode)
        .children?.[0] as ColumnNode;
      expect(movedColumn.children?.map((c: SimpleElementNode) => c.id)).toEqual([11, 12, 10]);
    });

    it('prepends when afterKey is null', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const e2 = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [e1, e2] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        buildNodeKey('element', 11),
        buildNodeKey('column', 30),
        null,
      );

      const movedColumn = ((result.nodes[0] as SectionNode).children?.[0] as RowNode)
        .children?.[0] as ColumnNode;
      expect(movedColumn.children?.map((c: SimpleElementNode) => c.id)).toEqual([11, 10]);
    });

    it('returns the same reference when the element is already at the target position', () => {
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const e2 = createSimpleElement({ id: 11, parent: { type: 'column', id: 30 } });
      const column = createColumnNode({ id: 30, children: [e1, e2] });
      const row = createRowNode({ id: 20, children: [column] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        buildNodeKey('element', 10),
        buildNodeKey('column', 30),
        null,
      );

      expect(result).toBe(tree);
    });
  });

  describe('cross-container move', () => {
    it('moves an element to a different column and updates parent fields', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const col30 = createColumnNode({ id: 30, children: [e1] });
      const col31 = createColumnNode({ id: 31, children: [] });
      const row = createRowNode({ id: 20, children: [col30, col31] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        buildNodeKey('element', 10),
        buildNodeKey('column', 31),
        null,
      );

      const row20 = (result.nodes[0] as SectionNode).children?.[0] as RowNode;
      const movedCol30 = row20.children?.[0] as ColumnNode;
      const movedCol31 = row20.children?.[1] as ColumnNode;
      expect(movedCol30.children).toEqual([]);
      expect(movedCol31.children?.[0].id).toBe(10);
      expect(movedCol31.children?.[0].parent).toEqual({ type: 'column', id: 31 });
      expect(movedCol31.children?.[0].parentKey).toBe(buildNodeKey('column', 31));
    });
  });

  describe('collision regression', () => {
    it('reorders sections correctly when section id equals page id', () => {
      resetIdCounter();
      const section1 = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        rowCount: 0,
      });
      const section2 = createSectionNode({
        id: 2,
        parent: { type: 'page', id: 1 },
        rowCount: 0,
      });

      const tree = createTreeApiResponse({
        pageId: 1,
        sections: [section1, section2],
      });

      // Move section 1 after section 2 (page id and section id=1 share `1`).
      const result = applyReorder(
        tree,
        buildNodeKey('section', 1),
        buildNodeKey('page', 1),
        buildNodeKey('section', 2),
      );

      expect(result.nodes.map((s) => s.id)).toEqual([2, 1]);
    });

    it('moves a row across sections when numeric IDs collide', () => {
      resetIdCounter();
      const row10 = createRowNode({
        id: 10,
        parent: { type: 'section', id: 1 },
        columnCount: 0,
      });
      const section1 = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row10],
      });
      const section2 = createSectionNode({
        id: 2,
        parent: { type: 'page', id: 1 },
        children: [],
      });

      const tree = createTreeApiResponse({
        pageId: 1,
        sections: [section1, section2],
      });

      const result = applyReorder(tree, buildNodeKey('row', 10), buildNodeKey('section', 2), null);

      const [movedSection1, movedSection2] = result.nodes as [SectionNode, SectionNode];
      expect(movedSection1.children).toEqual([]);
      expect(movedSection2.children).toHaveLength(1);
      expect(movedSection2.children?.[0].id).toBe(10);
      expect(movedSection2.children?.[0].parent).toEqual({ type: 'section', id: 2 });
    });
  });

  describe('edge cases', () => {
    it('returns the original tree when the element is not found', () => {
      const tree = createTreeApiResponse({ pageId: 1 });
      const result = applyReorder(
        tree,
        buildNodeKey('element', 9999),
        buildNodeKey('column', 30),
        null,
      );
      expect(result).toBe(tree);
    });

    it('returns the original tree when the target parent is not in the maps', () => {
      const tree = createTreeApiResponse({ pageId: 1 });
      const firstSection = tree.nodes[0] as SectionNode;
      const result = applyReorder(tree, firstSection.nodeKey, buildNodeKey('page', 9999), null);
      expect(result).toBe(tree);
    });

    it('appends to the end when afterKey is not found in the target', () => {
      resetIdCounter();
      const e1 = createSimpleElement({ id: 10, parent: { type: 'column', id: 30 } });
      const col30 = createColumnNode({ id: 30, children: [e1] });
      const col31 = createColumnNode({
        id: 31,
        children: [createSimpleElement({ id: 20, parent: { type: 'column', id: 31 } })],
      });
      const row = createRowNode({ id: 40, children: [col30, col31] });
      const section = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] });

      const result = applyReorder(
        tree,
        buildNodeKey('element', 10),
        buildNodeKey('column', 31),
        buildNodeKey('element', 9999),
      );

      const row40 = (result.nodes[0] as SectionNode).children?.[0] as RowNode;
      const col31Result = row40.children?.[1] as ColumnNode;
      expect(col31Result.children?.map((c: SimpleElementNode) => c.id)).toEqual([20, 10]);
    });
  });
});
